<?php

namespace App\Events\Twitch;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription to a channel this app serves expired (channel.subscription.end).
 */
class ChannelSubscriptionEnded
{
    use Dispatchable;

    /**
     * @param  CarbonImmutable  $endedAt  When Twitch sent the notification
     */
    public function __construct(
        public string $broadcasterId,
        public string $userId,
        public string $userLogin,
        public string $userName,
        public string $tier,
        public bool $isGift,
        public CarbonImmutable $endedAt,
    ) {}
}
