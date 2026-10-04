<?php

namespace App\Events\Twitch;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Another channel raided a channel this app serves (channel.raid).
 */
class ChannelRaided
{
    use Dispatchable;

    public function __construct(
        public string $broadcasterId,
        public string $fromBroadcasterId,
        public string $fromBroadcasterLogin,
        public string $fromBroadcasterName,
        public int $viewers,
    ) {}
}
