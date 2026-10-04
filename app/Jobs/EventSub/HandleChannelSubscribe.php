<?php

namespace App\Jobs\EventSub;

use App\Events\Twitch\ChannelSubscribed;

/**
 * channel.subscribe: a new or gifted subscription.
 */
class HandleChannelSubscribe extends EventSubJob
{
    protected function process(): void
    {
        ChannelSubscribed::dispatch(
            $this->string('broadcaster_user_id'),
            $this->string('user_id'),
            $this->string('user_login'),
            $this->string('user_name'),
            $this->string('tier'),
            (bool) ($this->event['is_gift'] ?? false),
        );
    }
}
