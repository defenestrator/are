<?php

namespace App\Models;

use App\IdentityProvider;
use App\Support\RequestMemo;
use Database\Factories\IdentityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account on one platform (a Twitch login, a YouTube
 * channel), belonging to exactly one user.
 *
 * @property IdentityProvider $provider
 * @property string $provider_user_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 */
class Identity extends Model
{
    /** A ban, or who holds an account, changed: memoised ban standing is stale (#173). */
    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forgetBans());
        static::deleted(fn () => RequestMemo::forgetBans());
    }

    /** @use HasFactory<IdentityFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'name',
        'email',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * Whether the stored access token has lapsed, or is within a minute of
     * it. A token with no known expiry is assumed to be still valid.
     */
    public function tokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->copy()->subMinute()->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Identity>  $query
     */
    public function scopeFor(Builder $query, IdentityProvider $provider, string $providerUserId): void
    {
        $query->where('provider', $provider)->where('provider_user_id', $providerUserId);
    }
}
