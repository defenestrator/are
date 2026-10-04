<?php

namespace App\Events\Twitch;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone followed a channel this app serves (channel.follow v2).
 */
class ChannelFollowed
{
    use Dispatchable;

    public function __construct(
        public string $broadcasterId,
        public string $userId,
        public string $userLogin,
        public string $userName,
        public CarbonImmutable $followedAt,
    ) {}
}
