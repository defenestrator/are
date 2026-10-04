<?php

use App\Data\ChatMessage;
use App\Events\Twitch\ChannelFollowed;
use App\Events\Twitch\ChannelRaided;
use App\Events\Twitch\ChannelSubscribed;
use App\Events\Twitch\ChatMessageReceived;
use App\Events\Twitch\RewardRedeemed;
use App\Jobs\EventSub\HandleChannelFollow;
use App\Jobs\EventSub\HandleChannelPointRedemption;
use App\Jobs\EventSub\HandleChannelRaid;
use App\Jobs\EventSub\HandleChannelSubscribe;
use App\Jobs\EventSub\HandleChannelSubscriptionEnd;
use App\Jobs\EventSub\HandleChatMessage;
use App\Jobs\EventSub\HandleStreamOffline;
use App\Jobs\EventSub\HandleStreamOnline;
use App\Jobs\SampleTwitchViewers;
use App\Models\ChannelPointRedemption;
use App\Models\StreamSession;
use App\Twitch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    // stream.online queues a viewer sample (#12), which calls Helix; these
    // tests are about sessions, so only that job is faked. Every other job
    // still runs synchronously. TwitchStreamMetricsTest covers sampling.
    Queue::fake([SampleTwitchViewers::class]);
});

/**
 * POST a signed EventSub notification, exactly as Twitch would.
 *
 * @see https://dev.twitch.tv/docs/eventsub/handling-webhook-events/#verifying-the-event-message
 */
function deliverEventSub(string $type, array $event, ?string $id = null, ?string $timestamp = null, ?string $secret = null)
{
    $id ??= (string) Str::uuid();
    $timestamp ??= now()->toIso8601ZuluString();
    $body = json_encode(['subscription' => ['type' => $type], 'event' => $event]);
    $signature = 'sha256='.hash_hmac('sha256', $id.$timestamp.$body, $secret ?? config('services.twitch.eventsub_secret'));

    return test()->call('POST', '/twitch/eventsub', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Twitch-Eventsub-Message-Id' => $id,
        'HTTP_Twitch-Eventsub-Message-Timestamp' => $timestamp,
        'HTTP_Twitch-Eventsub-Message-Signature' => $signature,
        'HTTP_Twitch-Eventsub-Message-Type' => 'notification',
    ], $body);
}

/**
 * Event objects shaped like the examples in Twitch's EventSub reference.
 *
 * @see https://dev.twitch.tv/docs/eventsub/eventsub-reference/
 */
function eventSubFixture(string $type, string $broadcasterId = '1000'): array
{
    $broadcaster = ['broadcaster_user_id' => $broadcasterId, 'broadcaster_user_login' => 'edos', 'broadcaster_user_name' => 'EDOS'];

    return match ($type) {
        'channel.chat.message' => $broadcaster + [
            'chatter_user_id' => '4145994',
            'chatter_user_login' => 'viewer32',
            'chatter_user_name' => 'viewer32',
            'message_id' => 'cc106a89-1814-919d-454c-f4f2f970aae7',
            'message' => ['text' => '  !vote 3  ', 'fragments' => [['type' => 'text', 'text' => '  !vote 3  ', 'cheermote' => null, 'emote' => null, 'mention' => null]]],
            'color' => '#00FF7F',
            'badges' => [['set_id' => 'moderator', 'id' => '1', 'info' => ''], ['set_id' => 'subscriber', 'id' => '12', 'info' => '16']],
            'message_type' => 'text',
            'cheer' => null,
            'reply' => null,
            'channel_points_custom_reward_id' => null,
            'source_broadcaster_user_id' => null,
            'source_message_id' => null,
            'source_badges' => null,
        ],
        'channel.channel_points_custom_reward_redemption.add' => $broadcaster + [
            'id' => '17fa2df1-ad76-4804-bfa5-a40ef63efe63',
            'user_id' => '1234',
            'user_login' => 'cooler_user',
            'user_name' => 'Cooler_User',
            'user_input' => 'pogchamp',
            'status' => 'unfulfilled',
            'reward' => ['id' => '92af127c-7326-4483-a52b-b0da0be61c01', 'title' => 'title', 'cost' => 100, 'prompt' => 'reward prompt'],
            'redeemed_at' => '2020-07-15T17:16:03.17106713Z',
        ],
        'channel.subscribe' => $broadcaster + [
            'user_id' => '1234',
            'user_login' => 'cool_user',
            'user_name' => 'Cool_User',
            'tier' => '1000',
            'is_gift' => false,
        ],
        'channel.subscription.end' => $broadcaster + [
            'user_id' => '1234',
            'user_login' => 'cool_user',
            'user_name' => 'Cool_User',
            'tier' => '1000',
            'is_gift' => false,
        ],
        'channel.raid' => [
            'from_broadcaster_user_id' => '1234',
            'from_broadcaster_user_login' => 'cool_user',
            'from_broadcaster_user_name' => 'Cool_User',
            'to_broadcaster_user_id' => $broadcasterId,
            'to_broadcaster_user_login' => 'edos',
            'to_broadcaster_user_name' => 'EDOS',
            'viewers' => 9001,
        ],
        'channel.follow' => $broadcaster + [
            'user_id' => '1234',
            'user_login' => 'cool_user',
            'user_name' => 'Cool_User',
            'followed_at' => '2020-07-15T18:16:11.17106713Z',
        ],
        'stream.online' => $broadcaster + [
            'id' => '9001',
            'type' => 'live',
            'started_at' => '2020-10-11T10:11:12.123Z',
        ],
        'stream.offline' => $broadcaster,
    };
}

dataset('queued types', [
    'chat message' => ['channel.chat.message', HandleChatMessage::class],
    'redemption' => ['channel.channel_points_custom_reward_redemption.add', HandleChannelPointRedemption::class],
    'subscribe' => ['channel.subscribe', HandleChannelSubscribe::class],
    'subscription end' => ['channel.subscription.end', HandleChannelSubscriptionEnd::class],
    'raid' => ['channel.raid', HandleChannelRaid::class],
    'follow' => ['channel.follow', HandleChannelFollow::class],
    'stream online' => ['stream.online', HandleStreamOnline::class],
    'stream offline' => ['stream.offline', HandleStreamOffline::class],
]);

// --- The webhook: verify, dedupe, queue -----------------------------------

test('a signed notification queues its job and answers 204', function (string $type, string $job) {
    Queue::fake();
    $id = (string) Str::uuid();
    $timestamp = now()->toIso8601ZuluString();

    deliverEventSub($type, eventSubFixture($type), id: $id, timestamp: $timestamp)->assertNoContent();

    Queue::assertPushed($job, fn ($queued) => $queued->messageId === $id
        && $queued->sentAt === $timestamp
        && $queued->event === eventSubFixture($type));
    Queue::assertCount(1);
})->with('queued types');

test('a bad signature is rejected and queues nothing', function (string $type) {
    Queue::fake();

    deliverEventSub($type, eventSubFixture($type), secret: 'wrong')->assertForbidden();

    Queue::assertNothingPushed();
})->with('queued types');

test('a message older than ten minutes is rejected and queues nothing', function (string $type) {
    Queue::fake();

    deliverEventSub($type, eventSubFixture($type), timestamp: now()->subMinutes(11)->toIso8601ZuluString())->assertForbidden();

    Queue::assertNothingPushed();
})->with('queued types');

test('a redelivered message id is queued once', function (string $type, string $job) {
    Queue::fake();
    $id = (string) Str::uuid();

    deliverEventSub($type, eventSubFixture($type), id: $id)->assertNoContent();
    deliverEventSub($type, eventSubFixture($type), id: $id)->assertNoContent();

    Queue::assertPushed($job, 1);
})->with('queued types');

test('channels this app does not serve queue nothing', function (string $type) {
    Queue::fake();

    deliverEventSub($type, eventSubFixture($type, broadcasterId: '9999'))->assertNoContent();

    Queue::assertNothingPushed();
})->with('queued types');

test('a redemption delivered over the webhook is stored for later consumers', function () {
    // No Queue::fake: the test queue runs jobs synchronously, end to end.
    deliverEventSub('channel.channel_points_custom_reward_redemption.add', eventSubFixture('channel.channel_points_custom_reward_redemption.add'))
        ->assertNoContent();

    expect(ChannelPointRedemption::where('twitch_redemption_id', '17fa2df1-ad76-4804-bfa5-a40ef63efe63')->exists())->toBeTrue();
});

// --- The jobs ---------------------------------------------------------------

test('the redemption job stores the redemption and the reward as redeemed', function () {
    Event::fake([RewardRedeemed::class]);

    (new HandleChannelPointRedemption('msg-1', '2020-07-15T17:16:04Z', eventSubFixture('channel.channel_points_custom_reward_redemption.add')))->handle();

    $redemption = ChannelPointRedemption::sole();
    expect($redemption->twitch_redemption_id)->toBe('17fa2df1-ad76-4804-bfa5-a40ef63efe63')
        ->and($redemption->broadcaster_id)->toBe('1000')
        ->and($redemption->twitch_user_id)->toBe('1234')
        ->and($redemption->user_login)->toBe('cooler_user')
        ->and($redemption->user_name)->toBe('Cooler_User')
        ->and($redemption->reward_id)->toBe('92af127c-7326-4483-a52b-b0da0be61c01')
        ->and($redemption->reward_title)->toBe('title')
        ->and($redemption->reward_cost)->toBe(100)
        ->and($redemption->reward_prompt)->toBe('reward prompt')
        ->and($redemption->user_input)->toBe('pogchamp')
        ->and($redemption->status)->toBe('unfulfilled')
        ->and($redemption->redeemed_at->toIso8601ZuluString())->toBe('2020-07-15T17:16:03Z');

    Event::assertDispatched(RewardRedeemed::class, fn ($e) => $e->redemption->is($redemption));
});

test('running a redemption job twice stores one redemption and announces it once', function () {
    Event::fake([RewardRedeemed::class]);
    $event = eventSubFixture('channel.channel_points_custom_reward_redemption.add');

    (new HandleChannelPointRedemption('msg-1', now()->toIso8601ZuluString(), $event))->handle();
    (new HandleChannelPointRedemption('msg-1', now()->toIso8601ZuluString(), $event))->handle();
    // Even under a different message id, Twitch's redemption id keeps it unique.
    (new HandleChannelPointRedemption('msg-2', now()->toIso8601ZuluString(), $event))->handle();

    expect(ChannelPointRedemption::count())->toBe(1);
    Event::assertDispatchedTimes(RewardRedeemed::class, 1);
});

test('an empty user input or prompt is stored as null', function () {
    $event = ['user_input' => '', 'reward' => ['id' => 'r', 'title' => 't', 'cost' => 5, 'prompt' => '']] + eventSubFixture('channel.channel_points_custom_reward_redemption.add');

    (new HandleChannelPointRedemption('msg-1', now()->toIso8601ZuluString(), $event))->handle();

    expect(ChannelPointRedemption::sole())
        ->user_input->toBeNull()
        ->reward_prompt->toBeNull();
});

test('the chat job normalises the message and hands it on', function () {
    Event::fake([ChatMessageReceived::class]);

    (new HandleChatMessage('msg-1', '2026-10-04T12:00:00Z', eventSubFixture('channel.chat.message')))->handle();

    Event::assertDispatched(ChatMessageReceived::class, function (ChatMessageReceived $e) {
        $m = $e->message;

        return $m instanceof ChatMessage
            && $m->messageId === 'cc106a89-1814-919d-454c-f4f2f970aae7'
            && $m->broadcasterId === '1000'
            && $m->chatterId === '4145994'
            && $m->chatterLogin === 'viewer32'
            && $m->text === '!vote 3'
            && $m->badges === ['moderator', 'subscriber']
            && $m->hasBadge('moderator')
            && ! $m->hasBadge('vip')
            && $m->bits === 0
            && $m->replyToMessageId === null
            && ! $m->isFromSharedChat()
            && $m->sentAt->toIso8601ZuluString() === '2026-10-04T12:00:00Z';
    });
});

test('a chat message relayed from another channel is marked as shared chat', function () {
    $message = ChatMessage::fromEvent(['source_broadcaster_user_id' => '5555', 'cheer' => ['bits' => 100], 'reply' => ['parent_message_id' => 'p1']] + eventSubFixture('channel.chat.message'), now()->toIso8601ZuluString());

    expect($message->isFromSharedChat())->toBeTrue()
        ->and($message->bits)->toBe(100)
        ->and($message->replyToMessageId)->toBe('p1');
});

test('the subscribe, raid and follow jobs announce what happened', function () {
    Event::fake([ChannelSubscribed::class, ChannelRaided::class, ChannelFollowed::class]);

    (new HandleChannelSubscribe('m1', now()->toIso8601ZuluString(), eventSubFixture('channel.subscribe')))->handle();
    (new HandleChannelRaid('m2', now()->toIso8601ZuluString(), eventSubFixture('channel.raid')))->handle();
    (new HandleChannelFollow('m3', now()->toIso8601ZuluString(), eventSubFixture('channel.follow')))->handle();

    Event::assertDispatched(ChannelSubscribed::class, fn ($e) => $e->broadcasterId === '1000' && $e->userId === '1234' && $e->tier === '1000' && $e->isGift === false);
    Event::assertDispatched(ChannelRaided::class, fn ($e) => $e->broadcasterId === '1000' && $e->fromBroadcasterId === '1234' && $e->fromBroadcasterLogin === 'cool_user' && $e->viewers === 9001);
    Event::assertDispatched(ChannelFollowed::class, fn ($e) => $e->broadcasterId === '1000' && $e->userLogin === 'cool_user' && $e->followedAt->toIso8601ZuluString() === '2020-07-15T18:16:11Z');
});

test('a job that already ran for a message id does nothing the second time', function () {
    Event::fake([ChannelSubscribed::class]);

    (new HandleChannelSubscribe('same', now()->toIso8601ZuluString(), eventSubFixture('channel.subscribe')))->handle();
    (new HandleChannelSubscribe('same', now()->toIso8601ZuluString(), eventSubFixture('channel.subscribe')))->handle();

    Event::assertDispatchedTimes(ChannelSubscribed::class, 1);
});

test('a job that fails is not marked handled, so its retry runs', function () {
    Event::listen(ChannelSubscribed::class, function () {
        static $calls = 0;
        if (++$calls === 1) {
            throw new RuntimeException('listener down');
        }
    });
    $job = new HandleChannelSubscribe('flaky', now()->toIso8601ZuluString(), eventSubFixture('channel.subscribe'));

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->handle();

    expect(Cache::has('twitch.eventsub.handled.flaky'))->toBeTrue();
});

test('jobs are unique per message id while queued', function () {
    $job = new HandleChatMessage('abc', now()->toIso8601ZuluString(), []);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->uniqueId())->toBe('abc');
});

test('stream.online opens a session and stream.offline closes it at the message time', function () {
    (new HandleStreamOnline('m1', now()->toIso8601ZuluString(), eventSubFixture('stream.online')))->handle();

    $session = StreamSession::sole();
    expect($session->broadcaster_id)->toBe('1000')
        ->and($session->twitch_stream_id)->toBe('9001')
        ->and($session->type)->toBe('live')
        ->and($session->started_at->toIso8601ZuluString())->toBe('2020-10-11T10:11:12Z')
        ->and($session->ended_at)->toBeNull();

    (new HandleStreamOffline('m2', '2020-10-11T13:00:00Z', eventSubFixture('stream.offline')))->handle();

    expect($session->fresh()->ended_at->toIso8601ZuluString())->toBe('2020-10-11T13:00:00Z')
        ->and(StreamSession::live()->count())->toBe(0);
});

test('a repeated stream.online keeps one session', function () {
    (new HandleStreamOnline('m1', now()->toIso8601ZuluString(), eventSubFixture('stream.online')))->handle();
    (new HandleStreamOnline('m2', now()->toIso8601ZuluString(), eventSubFixture('stream.online')))->handle();

    expect(StreamSession::count())->toBe(1);
});

test('stream.offline closes only the latest open session of that channel', function () {
    $other = StreamSession::factory()->create(['broadcaster_id' => '2000']);
    $older = StreamSession::factory()->ended()->create(['started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHours(2)]);
    $current = StreamSession::factory()->create(['started_at' => now()->subHour()]);

    (new HandleStreamOffline('m1', now()->toIso8601ZuluString(), eventSubFixture('stream.offline')))->handle();

    expect($current->fresh()->ended_at)->not->toBeNull()
        ->and($older->fresh()->ended_at->equalTo($older->ended_at))->toBeTrue()
        ->and($other->fresh()->ended_at)->toBeNull();
});

test('stream.offline matches the stream id when Twitch sends one', function () {
    $named = StreamSession::factory()->create(['twitch_stream_id' => '777', 'started_at' => now()->subHours(3)]);
    $latest = StreamSession::factory()->create(['started_at' => now()->subHour()]);

    (new HandleStreamOffline('m1', now()->toIso8601ZuluString(), ['id' => '777'] + eventSubFixture('stream.offline')))->handle();

    expect($named->fresh()->ended_at)->not->toBeNull()
        ->and($latest->fresh()->ended_at)->toBeNull();
});

// Reported by Andras on #37: offline(A) and online(B) ran out of order after a reconnect.
test('a late stream.offline for the previous stream does not end the stream that replaced it', function () {
    $offlineSentAt = now()->subSeconds(30);
    $bStarted = now()->subSeconds(10);

    (new HandleStreamOnline('msg-online-a', now()->subHour()->toIso8601ZuluString(), [
        'id' => 'A', 'broadcaster_user_id' => '1000', 'type' => 'live', 'started_at' => now()->subHour()->toIso8601ZuluString(),
    ]))->handle();
    (new HandleStreamOnline('msg-online-b', $bStarted->toIso8601ZuluString(), [
        'id' => 'B', 'broadcaster_user_id' => '1000', 'type' => 'live', 'started_at' => $bStarted->toIso8601ZuluString(),
    ]))->handle();

    (new HandleStreamOffline('msg-offline-a', $offlineSentAt->toIso8601ZuluString(), ['broadcaster_user_id' => '1000']))->handle();

    expect(StreamSession::where('twitch_stream_id', 'B')->first()->ended_at)->toBeNull()
        // A was closed provisionally at B's start; the late offline corrects it to the real end.
        ->and(StreamSession::where('twitch_stream_id', 'A')->first()->ended_at->toIso8601ZuluString())->toBe($offlineSentAt->toIso8601ZuluString());
});

test('stream.offline sent before the only open session started changes nothing', function () {
    $session = StreamSession::factory()->create(['started_at' => now()->subSeconds(10)]);

    (new HandleStreamOffline('m1', now()->subSeconds(30)->toIso8601ZuluString(), eventSubFixture('stream.offline')))->handle();

    expect($session->fresh()->ended_at)->toBeNull();
});

test('stream.offline with no open session changes nothing', function () {
    $ended = StreamSession::factory()->ended()->create();

    (new HandleStreamOffline('m1', now()->addHour()->toIso8601ZuluString(), eventSubFixture('stream.offline')))->handle();

    expect($ended->fresh()->ended_at->equalTo($ended->ended_at))->toBeTrue();
});

test('a new stream ends a session whose stream.offline never arrived', function () {
    $stale = StreamSession::factory()->create(['started_at' => '2020-10-10T10:00:00Z']);

    (new HandleStreamOnline('m1', now()->toIso8601ZuluString(), eventSubFixture('stream.online')))->handle();

    expect($stale->fresh()->ended_at->toIso8601ZuluString())->toBe('2020-10-11T10:11:12Z')
        ->and(StreamSession::live()->pluck('twitch_stream_id')->all())->toBe(['9001']);
});

// --- Subscribing -------------------------------------------------------------

test('twitch:eventsub-subscribe creates every type with the version and condition Twitch documents', function () {
    config(['services.twitch.broadcaster_ids' => []]);
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
        'api.twitch.tv/helix/eventsub/subscriptions' => Http::response(['data' => []], 202),
    ]);

    $this->artisan('twitch:eventsub-subscribe')->assertSuccessful();

    $sent = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_contains($r->url(), '/eventsub/subscriptions'))
        ->mapWithKeys(fn (Request $r) => [$r['type'] => ['version' => $r['version'], 'condition' => $r['condition']]]);

    expect($sent->keys()->all())->toBe(Twitch::EVENTSUB_TYPES)
        ->and($sent['channel.chat.message'])->toBe(['version' => '1', 'condition' => ['broadcaster_user_id' => '1000', 'user_id' => '1000']])
        ->and($sent['channel.channel_points_custom_reward_redemption.add'])->toBe(['version' => '1', 'condition' => ['broadcaster_user_id' => '1000']])
        ->and($sent['channel.subscribe'])->toBe(['version' => '1', 'condition' => ['broadcaster_user_id' => '1000']])
        ->and($sent['channel.subscription.end'])->toBe(['version' => '1', 'condition' => ['broadcaster_user_id' => '1000']])
        ->and($sent['channel.raid'])->toBe(['version' => '1', 'condition' => ['to_broadcaster_user_id' => '1000']])
        ->and($sent['channel.follow'])->toBe(['version' => '2', 'condition' => ['broadcaster_user_id' => '1000', 'moderator_user_id' => '1000']])
        ->and($sent['stream.online'])->toBe(['version' => '1', 'condition' => ['broadcaster_user_id' => '1000']])
        ->and($sent['stream.offline'])->toBe(['version' => '1', 'condition' => ['broadcaster_user_id' => '1000']]);
});

test('twitch:eventsub-subscribe reports a missing scope and carries on with the other types', function () {
    config(['services.twitch.broadcaster_ids' => []]);
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
        'api.twitch.tv/helix/eventsub/subscriptions' => fn (Request $r) => $r['type'] === 'channel.follow'
            ? Http::response(['error' => 'Forbidden', 'status' => 403, 'message' => 'subscription missing proper authorization'], 403)
            : Http::response(['data' => []], 202),
    ]);

    $this->artisan('twitch:eventsub-subscribe')
        ->expectsOutputToContain('1000: channel.follow failed (403): subscription missing proper authorization')
        ->expectsOutputToContain('1000: stream.offline')
        ->assertFailed();
});

test('the broadcaster connection asks for every scope the new types need', function () {
    expect(Twitch::BROADCASTER_SCOPES)->toContain(
        'user:read:chat', 'user:bot', 'channel:bot',
        'channel:read:redemptions', 'channel:read:subscriptions', 'moderator:read:followers',
    );
});
