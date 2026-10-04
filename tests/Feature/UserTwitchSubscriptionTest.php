<?php

use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;

// Twitch login records one row per (user, channel) with updateOrCreate. The
// table has a composite primary key, which Eloquent does not model natively.

test('a changed tier updates the existing row instead of adding one', function () {
    $user = User::factory()->create();
    $key = ['user_id' => $user->id, 'broadcaster_id' => '1000'];

    UserTwitchSubscription::updateOrCreate($key, ['twitch_subscription' => TwitchSubscription::Tier1]);
    UserTwitchSubscription::updateOrCreate($key, ['twitch_subscription' => TwitchSubscription::Tier3]);

    expect(UserTwitchSubscription::count())->toBe(1)
        ->and($user->getHighestSubscription())->toBe(TwitchSubscription::Tier3);
});

test('updating one channel row leaves the user\'s other channel rows alone', function () {
    $user = User::factory()->create();
    UserTwitchSubscription::updateOrCreate(['user_id' => $user->id, 'broadcaster_id' => '1000'], ['twitch_subscription' => TwitchSubscription::Tier1]);
    UserTwitchSubscription::updateOrCreate(['user_id' => $user->id, 'broadcaster_id' => '2000'], ['twitch_subscription' => TwitchSubscription::Tier2]);

    UserTwitchSubscription::updateOrCreate(['user_id' => $user->id, 'broadcaster_id' => '1000'], ['twitch_subscription' => TwitchSubscription::None]);

    expect(UserTwitchSubscription::where('broadcaster_id', '2000')->value('twitch_subscription'))->toBe(TwitchSubscription::Tier2)
        ->and(UserTwitchSubscription::where('broadcaster_id', '1000')->value('twitch_subscription'))->toBe(TwitchSubscription::None);
});

test('deleting one channel row leaves the user\'s other channel rows alone', function () {
    $user = User::factory()->create();
    $one = UserTwitchSubscription::create(['user_id' => $user->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier1]);
    UserTwitchSubscription::create(['user_id' => $user->id, 'broadcaster_id' => '2000', 'twitch_subscription' => TwitchSubscription::Tier2]);

    $one->delete();

    expect(UserTwitchSubscription::pluck('broadcaster_id')->all())->toBe(['2000']);
});
