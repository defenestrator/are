<?php

namespace App\Models;

use App\TwitchSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserTwitchSubscription extends Model
{
    /** @use HasFactory<\Database\Factories\UserTwitchSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'broadcaster_id',
        'twitch_subscription',
    ];

    public function casts(): array
    {
        return [
            'twitch_subscription' => TwitchSubscription::class,
        ];
    }

    public static function broadcasterId(): string
    {
        return config('services.twitch.broadcaster_id');
    }

    public static function allBroadcasterIds(): array
    {
        // TODO: Remove these calls to env if I play on deploying with config caching
        // - falsyvalue
        return array_merge([config('services.twitch.broadcaster_id')], config('services.twitch.friend_ids'));
    }
}
