<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TwitchBan extends Model
{
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
