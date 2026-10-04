<?php

namespace App\Events\Twitch;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone subscribed to a channel this app serves (channel.subscribe).
 * Resubscriptions arrive as channel.subscription.message, which is not handled yet.
 */
class ChannelSubscribed
{
    use Dispatchable;

    /**
     * @param  string  $tier  "1000", "2000" or "3000"
     */
    public function __construct(
        public string $broadcasterId,
        public string $userId,
        public string $userLogin,
        public string $userName,
        public string $tier,
        public bool $isGift,
    ) {}
}
