<?php

use App\Events\Twitch\ChannelSubscribed;
use App\Jobs\EventSub\HandleChannelSubscribe;
use App\Listeners\RecordTwitchSubscription;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

function subscribeEvent(string $userId = '1234', string $tier = '1000', string $broadcasterId = '1000', bool $isGift = false): ChannelSubscribed
{
    return new ChannelSubscribed($broadcasterId, $userId, 'cool_user', 'Cool_User', $tier, $isGift);
}

test('channel.subscribe sets the tier of the linked user for that channel', function () {
    $viewer = User::factory()->twitch('1234')->create();

    (new HandleChannelSubscribe('msg-1', now()->toIso8601ZuluString(), [
        'user_id' => '1234', 'user_login' => 'cool_user', 'user_name' => 'Cool_User',
        'broadcaster_user_id' => '1000', 'broadcaster_user_login' => 'edos', 'broadcaster_user_name' => 'EDOS',
        'tier' => '2000', 'is_gift' => false,
    ]))->handle();

    expect(UserTwitchSubscription::where('user_id', $viewer->id)->where('broadcaster_id', '1000')->value('twitch_subscription'))
        ->toBe(TwitchSubscription::Tier2)
        ->and($viewer->getHighestSubscription())->toBe(TwitchSubscription::Tier2);
});

test('a gifted sub counts for the recipient', function () {
    $recipient = User::factory()->twitch('1234')->create();

    event(subscribeEvent(isGift: true));

    expect($recipient->getHighestSubscription())->toBe(TwitchSubscription::Tier1);
});

test('a new tier replaces the old row for that channel and leaves other channels alone', function () {
    $viewer = User::factory()->twitch('1234')->create();
    UserTwitchSubscription::create(['user_id' => $viewer->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::None]);
    UserTwitchSubscription::create(['user_id' => $viewer->id, 'broadcaster_id' => '2000', 'twitch_subscription' => TwitchSubscription::Tier1]);

    event(subscribeEvent(tier: '3000'));
    event(subscribeEvent(tier: '3000'));

    expect(UserTwitchSubscription::where('user_id', $viewer->id)->count())->toBe(2)
        ->and(UserTwitchSubscription::where('broadcaster_id', '1000')->value('twitch_subscription'))->toBe(TwitchSubscription::Tier3)
        ->and(UserTwitchSubscription::where('broadcaster_id', '2000')->value('twitch_subscription'))->toBe(TwitchSubscription::Tier1);
});

test('a sub from a Twitch account nobody has linked changes nothing', function () {
    User::factory()->twitch('5555')->create();
    $users = User::count();

    event(subscribeEvent(userId: '9999'));

    expect(UserTwitchSubscription::count())->toBe(0)
        ->and(User::count())->toBe($users);
});

test('an unknown tier changes nothing', function (string $tier) {
    User::factory()->twitch('1234')->create();

    event(subscribeEvent(tier: $tier));

    expect(UserTwitchSubscription::count())->toBe(0);
})->with(['0000', 'prime', '']);

test('the new tier applies straight away to the question limit', function () {
    $viewer = User::factory()->twitch('1234')->create();
    Question::factory()->count(6)->for($viewer)->create();
    expect($viewer->canSubmitQuestion())->toBeTrue(); // non-subscribers have no cap

    event(subscribeEvent(tier: '1000'));
    Topic::set('Kale');

    expect($viewer->canSubmitQuestion())->toBeFalse(); // Tier 1 caps at 6 active questions
});

test('the tier is recorded by a queued listener', function () {
    Queue::fake();

    event(subscribeEvent());

    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === RecordTwitchSubscription::class);
});
