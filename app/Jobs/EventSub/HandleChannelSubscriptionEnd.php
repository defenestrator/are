<?php

namespace App\Jobs\EventSub;

use App\Events\Twitch\ChannelSubscriptionEnded;
use Carbon\CarbonImmutable;

/**
 * channel.subscription.end: a subscription expired.
 */
class HandleChannelSubscriptionEnd extends EventSubJob
{
    protected function process(): void
    {
        ChannelSubscriptionEnded::dispatch(
            $this->string('broadcaster_user_id'),
            $this->string('user_id'),
            $this->string('user_login'),
            $this->string('user_name'),
            $this->string('tier'),
            (bool) ($this->event['is_gift'] ?? false),
            CarbonImmutable::parse($this->sentAt),
        );
    }
}
