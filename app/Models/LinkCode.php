<?php

namespace App\Models;

use App\Exceptions\IdentityLinkException;
use App\Identities;
use App\IdentityProvider;
use Database\Factories\LinkCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A one-time code for linking a chat account. Short, unambiguous, single use,
 * valid for fifteen minutes, and stored only as an HMAC.
 *
 * Linking takes two steps, so a code typed by someone else (someone talked
 * into typing it) cannot attach their account without the owner noticing:
 *
 * 1. `!link CODE` in chat claims the code for that chat account: a pending link.
 * 2. The code's owner sees the account's name and id in Settings and
 *    confirms it, which links it through Identities, or rejects it. A pending
 *    link they do not confirm before the code expires is discarded.
 *
 * Codes are typed in public chat, so a bot can replay one and win the race to
 * claim it (#102). Any second account typing a claimed code therefore voids
 * it (contested_at), and the owner is told to get a new one. A race then
 * costs the owner a retry, never their account.
 *
 * @property IdentityProvider|null $pending_provider
 * @property string|null $pending_provider_user_id
 * @property string|null $pending_name
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $contested_at
 * @property Carbon|null $used_at
 */
class LinkCode extends Model
{
    /** @use HasFactory<LinkCodeFactory> */
    use HasFactory, MassPrunable;

    /** No 0/O, 1/I/L or 5/S, so a code read off the screen types back correctly. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';

    public const LENGTH = 8;

    public const MINUTES = 15;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'pending_provider',
        'pending_provider_user_id',
        'pending_name',
        'claimed_at',
        'contested_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'contested_at' => 'datetime',
            'used_at' => 'datetime',
            'pending_provider' => IdentityProvider::class,
        ];
    }

    /**
     * Issue a fresh code for $user and return it in its display form
     * (ABCD-EFGH). Any code the user had not used yet, and any link it was
     * waiting on, stops working.
     */
    public static function issueFor(User $user): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $user->linkCodes()->whereNull('used_at')->delete();
        $user->linkCodes()->create([
            'code_hash' => self::hash($code),
            'expires_at' => now()->addMinutes(self::MINUTES),
        ]);

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * Uppercase and drop everything but letters and digits, so "abcd efgh",
     * "ABCD-EFGH" and "abcdefgh" are the same code.
     */
    public static function normalize(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) config('app.key'));
    }

    /**
     * The unused, unexpired, unclaimed code matching what was typed, if any.
     */
    public static function findUsable(string $typed): ?self
    {
        $code = self::findTyped($typed);

        return $code?->claimed_at === null ? $code : null;
    }

    /**
     * The live code matching what was typed, claimed or not: unused,
     * unexpired and not contested.
     */
    public static function findTyped(string $typed): ?self
    {
        $normalized = self::normalize($typed);
        if (strlen($normalized) !== self::LENGTH) {
            return null;
        }

        return self::usable()->where('code_hash', self::hash($normalized))->first();
    }

    /**
     * Whether $providerUserId on $provider is the account that claimed this code.
     */
    public function isClaimedBy(IdentityProvider $provider, string $providerUserId): bool
    {
        return $this->claimed_at !== null
            && $this->pending_provider === $provider
            && $this->pending_provider_user_id === $providerUserId;
    }

    /**
     * A second account typed this claimed code: void it and its pending link.
     * Returns false if it was already used, contested or never claimed.
     */
    public function contest(): bool
    {
        return self::whereKey($this->id)
            ->whereNotNull('claimed_at')
            ->whereNull('used_at')
            ->whereNull('contested_at')
            ->update(['contested_at' => now()]) === 1;
    }

    /**
     * Step 1: record that $providerUserId typed this code. Returns false if
     * another message claimed it first. Links nothing.
     */
    public function claim(IdentityProvider $provider, string $providerUserId, string $name): bool
    {
        return self::whereKey($this->id)->usable()->whereNull('claimed_at')->update([
            'pending_provider' => $provider->value,
            'pending_provider_user_id' => $providerUserId,
            'pending_name' => mb_substr($name, 0, 255),
            'claimed_at' => now(),
        ]) === 1;
    }

    /**
     * Mark the code used. Returns false if it was already used.
     */
    public function consume(): bool
    {
        return self::whereKey($this->id)->whereNull('used_at')->whereNull('contested_at')->update(['used_at' => now()]) === 1;
    }

    /**
     * Step 2: the owner confirms the pending link, which links the chat
     * account through Identities with the usual rules. The code is used up
     * either way; a refused link leaves nothing pending.
     *
     * @throws IdentityLinkException
     */
    public function confirm(): Identity
    {
        // The page that offered this may be stale: read the code as it is now.
        $current = self::find($this->id);
        if ($current === null) {
            throw new IdentityLinkException('There is no link waiting for you to confirm.');
        }
        $this->setRawAttributes($current->getAttributes(), true);

        if ($this->contested_at !== null) {
            throw IdentityLinkException::contested();
        }

        if ($this->claimed_at === null || $this->used_at !== null || $this->pending_provider === null) {
            throw new IdentityLinkException('There is no link waiting for you to confirm.');
        }

        if ($this->expires_at->isPast()) {
            $this->delete();

            throw new IdentityLinkException('That link request expired. Get a new code and type it again.');
        }

        $provider = $this->pending_provider;
        $providerUserId = (string) $this->pending_provider_user_id;

        try {
            return DB::transaction(function () use ($provider, $providerUserId) {
                if (! $this->consume()) {
                    throw new IdentityLinkException('That link request was already handled.');
                }

                // The chat account may have been banned since it typed the code.
                if (UserBan::inEffect()->forAccount($provider, $providerUserId)->exists()) {
                    throw IdentityLinkException::accountBanned($provider);
                }

                return Identities::linkAccount($this->user, $provider, $providerUserId, ['name' => $this->pending_name]);
            });
        } catch (IdentityLinkException $e) {
            $this->delete();

            throw $e;
        }
    }

    /**
     * The owner says the pending account is not theirs: discard it.
     */
    public function reject(): void
    {
        $this->delete();
    }

    /**
     * @param  Builder<LinkCode>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('contested_at')->where('expires_at', '>', now());
    }

    /**
     * Codes voided because more than one account typed them, so the owner
     * can be told. Issuing a new code clears them.
     *
     * @param  Builder<LinkCode>  $query
     */
    public function scopeContested(Builder $query): void
    {
        $query->whereNotNull('contested_at')->whereNull('used_at');
    }

    /**
     * Links typed in chat and waiting for the owner, not yet expired.
     *
     * @param  Builder<LinkCode>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->usable()->whereNotNull('claimed_at');
    }

    /**
     * Expired or used codes are kept a day for debugging, then pruned.
     *
     * @return Builder<LinkCode>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
