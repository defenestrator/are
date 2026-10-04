<?php

namespace App\Jobs\EventSub;

use App\Events\Twitch\ChannelFollowed;
use Carbon\CarbonImmutable;

/**
 * channel.follow (v2): a new follower.
 */
class HandleChannelFollow extends EventSubJob
{
    protected function process(): void
    {
        ChannelFollowed::dispatch(
            $this->string('broadcaster_user_id'),
            $this->string('user_id'),
            $this->string('user_login'),
            $this->string('user_name'),
            CarbonImmutable::parse($this->string('followed_at') ?: $this->sentAt),
        );
    }
}
