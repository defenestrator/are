<?php

namespace App\Models;

use Database\Factories\LinkCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code that links the chat account it is typed from (`!link CODE`)
 * to the user who asked for it. Short, unambiguous, single use, valid for
 * fifteen minutes, and stored only as an HMAC.
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
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Issue a fresh code for $user and return it in its display form
     * (ABCD-EFGH). Any code the user had not used yet stops working.
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
     * The unused, unexpired code matching what was typed, if any.
     */
    public static function findUsable(string $typed): ?self
    {
        $normalized = self::normalize($typed);
        if (strlen($normalized) !== self::LENGTH) {
            return null;
        }

        return self::usable()->where('code_hash', self::hash($normalized))->first();
    }

    /**
     * Mark the code used. Returns false if another message used it first.
     */
    public function consume(): bool
    {
        return self::whereKey($this->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
    }

    /**
     * @param  Builder<LinkCode>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
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
