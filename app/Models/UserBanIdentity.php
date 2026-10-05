<?php

namespace App\Models;

use App\IdentityProvider;
use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform account a local ban covers, copied when the ban was placed. It
 * has no foreign key to identities, so it survives the account being deleted.
 *
 * @property IdentityProvider $provider
 * @property string $provider_user_id
 */
class UserBanIdentity extends Model
{
    /** A ban, or who holds an account, changed: memoised ban standing is stale (#173). */
    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forgetBans());
        static::deleted(fn () => RequestMemo::forgetBans());
    }

    protected $fillable = [
        'provider',
        'provider_user_id',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
        ];
    }

    /**
     * @return BelongsTo<UserBan, $this>
     */
    public function ban(): BelongsTo
    {
        return $this->belongsTo(UserBan::class, 'user_ban_id');
    }
}
