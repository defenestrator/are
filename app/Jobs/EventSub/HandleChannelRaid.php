<?php

namespace App\Jobs\EventSub;

use App\Events\Twitch\ChannelRaided;

/**
 * channel.raid, subscribed with to_broadcaster_user_id: an incoming raid.
 */
class HandleChannelRaid extends EventSubJob
{
    protected function process(): void
    {
        ChannelRaided::dispatch(
            $this->string('to_broadcaster_user_id'),
            $this->string('from_broadcaster_user_id'),
            $this->string('from_broadcaster_user_login'),
            $this->string('from_broadcaster_user_name'),
            (int) ($this->event['viewers'] ?? 0),
        );
    }
}
