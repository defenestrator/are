<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A channel-point reward redeemed on a channel this app serves.
 *
 * @property string $twitch_redemption_id
 * @property string $broadcaster_id
 * @property string $twitch_user_id
 * @property string $user_login
 * @property string $user_name
 * @property string $reward_id
 * @property string $reward_title
 * @property int $reward_cost
 * @property string|null $reward_prompt
 * @property string|null $user_input
 * @property string $status
 * @property \Illuminate\Support\Carbon $redeemed_at
 */
class ChannelPointRedemption extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelPointRedemptionFactory> */
    use HasFactory;

    protected $fillable = [
        'twitch_redemption_id',
        'broadcaster_id',
        'twitch_user_id',
        'user_login',
        'user_name',
        'reward_id',
        'reward_title',
        'reward_cost',
        'reward_prompt',
        'user_input',
        'status',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_cost' => 'integer',
            'redeemed_at' => 'datetime',
        ];
    }
}
