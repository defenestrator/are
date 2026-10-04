<?php

use App\Events\Twitch\ChannelSubscribed;
use App\Events\Twitch\ChannelSubscriptionEnded;
use App\Jobs\EventSub\HandleChannelSubscribe;
use App\Jobs\EventSub\HandleChannelSubscriptionEnd;
use App\Listeners\RecordTwitchSubscription;
use App\Listeners\RevokeTwitchSubscription;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Carbon\CarbonImmutable;
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

// --- channel.subscription.end (#80) ---------------------------------------------

function subscriptionEndEvent(string $userId = '1234', string $broadcasterId = '1000', ?CarbonImmutable $endedAt = null): ChannelSubscriptionEnded
{
    return new ChannelSubscriptionEnded($broadcasterId, $userId, 'cool_user', 'Cool_User', '1000', false, $endedAt ?? CarbonImmutable::now());
}

test('channel.subscription.end removes the linked user\'s tier for that channel only', function () {
    $viewer = User::factory()->twitch('1234')->create();
    UserTwitchSubscription::create(['user_id' => $viewer->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier2]);
    UserTwitchSubscription::create(['user_id' => $viewer->id, 'broadcaster_id' => '2000', 'twitch_subscription' => TwitchSubscription::Tier1]);
    $this->travel(1)->seconds();

    (new HandleChannelSubscriptionEnd('msg-end', now()->toIso8601ZuluString(), [
        'user_id' => '1234', 'user_login' => 'cool_user', 'user_name' => 'Cool_User',
        'broadcaster_user_id' => '1000', 'broadcaster_user_login' => 'edos', 'broadcaster_user_name' => 'EDOS',
        'tier' => '2000', 'is_gift' => false,
    ]))->handle();

    expect(UserTwitchSubscription::where('user_id', $viewer->id)->pluck('broadcaster_id')->all())->toBe(['2000'])
        ->and($viewer->getHighestSubscription())->toBe(TwitchSubscription::Tier1);
});

test('a lapsed sub goes back to the non-subscriber rules at once', function () {
    $viewer = User::factory()->twitch('1234')->create();
    event(subscribeEvent());
    Topic::set('Kale');
    Question::factory()->count(6)->for($viewer)->create();
    expect($viewer->canSubmitQuestion())->toBeFalse();

    $this->travel(1)->seconds();
    event(subscriptionEndEvent());

    expect($viewer->canSubmitQuestion())->toBeTrue();
});

test('channel.subscription.end for an unlinked Twitch account changes nothing', function () {
    $viewer = User::factory()->twitch('5555')->create();
    UserTwitchSubscription::create(['user_id' => $viewer->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier1]);
    $users = User::count();

    event(subscriptionEndEvent(userId: '9999'));

    expect(UserTwitchSubscription::count())->toBe(1)
        ->and(User::count())->toBe($users);
});

test('an end that arrives after a newer resubscribe or sign-in leaves the tier alone', function () {
    $viewer = User::factory()->twitch('1234')->create();
    $endSentAt = CarbonImmutable::now();

    // The resubscribe's job ran first, a little after Twitch sent the end.
    $this->travel(5)->seconds();
    event(subscribeEvent(tier: '3000'));

    event(subscriptionEndEvent(endedAt: $endSentAt));

    expect($viewer->getHighestSubscription())->toBe(TwitchSubscription::Tier3);
});

test('the tier is revoked by a queued listener', function () {
    Queue::fake();

    event(subscriptionEndEvent());

    Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === RevokeTwitchSubscription::class);
});
