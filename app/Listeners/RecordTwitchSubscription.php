<?php

namespace App\Listeners;

use App\Events\Twitch\ChannelSubscribed;
use App\Identities;
use App\IdentityProvider;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Gives a viewer their sub-tier perks (such as the question limit) as soon as
 * they subscribe, instead of at their next Twitch sign-in.
 *
 * A Twitch account no user has linked is skipped: their tier is read from
 * Twitch when they first sign in, as before.
 */
class RecordTwitchSubscription implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ChannelSubscribed $event): void
    {
        $tier = TwitchSubscription::tryFrom($event->tier);
        if ($tier === null || ! $tier->isSubscribed()) {
            logger()->warning('channel.subscribe with an unknown tier', ['tier' => $event->tier]);

            return;
        }

        $user = Identities::findUser(IdentityProvider::Twitch, $event->userId);
        if ($user === null) {
            return;
        }

        // An atomic upsert on the (user_id, broadcaster_id) primary key, so it
        // cannot collide with the same row being written by a concurrent sign-in.
        UserTwitchSubscription::upsert(
            [[
                'user_id' => $user->id,
                'broadcaster_id' => $event->broadcasterId,
                'twitch_subscription' => $tier->value,
            ]],
            ['user_id', 'broadcaster_id'],
            ['twitch_subscription'],
        );
    }
}
