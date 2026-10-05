<?php

namespace App\Models;

use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TwitchBan extends Model
{
    /** A ban, or who holds an account, changed: memoised ban standing is stale (#173). */
    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forgetBans());
        static::deleted(fn () => RequestMemo::forgetBans());
    }

    protected $fillable = [
        'broadcaster_id',
        'twitch_user_id',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Permanent bans, and timeouts that have not yet expired.
     *
     * @param  Builder<TwitchBan>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
