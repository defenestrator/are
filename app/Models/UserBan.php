<?php

namespace App\Models;

use App\IdentityProvider;
use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class UserBan extends Model
{
    /** A ban, or who holds an account, changed: memoised ban standing is stale (#173). */
    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forgetBans());
        static::deleted(fn () => RequestMemo::forgetBans());
    }

    protected $fillable = [
        'user_id',
        'moderator_id',
        'reason',
        'ends_at',
        'lifted_at',
    ];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Not lifted, and either permanent or not yet expired.
     *
     * @param  Builder<UserBan>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->whereNull('lifted_at')
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Bans that cover $user: placed on them, or on any platform account they
     * hold now. The second half is what keeps a ban on a re-created account.
     *
     * @param  Builder<UserBan>  $query
     */
    public function scopeAppliesTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('user_bans.user_id', $user->id)
            ->orWhereHas('identities', fn (Builder $banned) => $banned->whereExists(fn (QueryBuilder $held) => $held
                ->selectRaw('1')
                ->from('identities')
                ->where('identities.user_id', $user->id)
                ->whereColumn('identities.provider', 'user_ban_identities.provider')
                ->whereColumn('identities.provider_user_id', 'user_ban_identities.provider_user_id'))));
    }

    /**
     * Bans that cover one platform account, whoever holds it (or nobody, if
     * the banned user deleted their account).
     *
     * @param  Builder<UserBan>  $query
     */
    public function scopeForAccount(Builder $query, IdentityProvider $provider, string $providerUserId): void
    {
        $query->whereHas('identities', fn (Builder $banned) => $banned
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId));
    }

    /**
     * The platform accounts this ban covers, copied when it was placed.
     *
     * @return HasMany<UserBanIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserBanIdentity::class);
    }

    /**
     * Null once the banned user deletes their account; the ban still holds
     * through its identities.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
