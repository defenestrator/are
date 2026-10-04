<?php

namespace App\Listeners;

use App\Events\Twitch\ChannelSubscriptionEnded;
use App\Identities;
use App\IdentityProvider;
use App\Models\UserTwitchSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Takes away a viewer's sub-tier perks when their subscription to a channel
 * ends, instead of at their next Twitch sign-in.
 *
 * Only a tier row last written before Twitch sent the end is removed. Jobs can
 * run out of order: if a resubscribe (channel.subscribe) or a sign-in sync has
 * written the row since, that is newer news, and the row stays.
 */
class RevokeTwitchSubscription implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ChannelSubscriptionEnded $event): void
    {
        $user = Identities::findUser(IdentityProvider::Twitch, $event->userId);
        if ($user === null) {
            return;
        }

        UserTwitchSubscription::where('user_id', $user->id)
            ->where('broadcaster_id', $event->broadcasterId)
            ->where('updated_at', '<=', $event->endedAt)
            ->delete();
    }
}
