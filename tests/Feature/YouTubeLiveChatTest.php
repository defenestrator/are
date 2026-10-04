<?php

use App\Chat\ChatCommandRegistry;
use App\Console\Commands\YouTubeChat;
use App\Events\YouTubeQuotaThresholdReached;
use App\IdentityProvider;
use App\Jobs\PollYouTubeLiveChat;
use App\Listeners\AlertOnYouTubeQuota;
use App\Models\ChatCommandRun;
use App\Models\Question;
use App\Models\User;
use App\Models\YouTubeLiveChat;
use App\YouTube\Quota;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

const YT_MESSAGES = 'www.googleapis.com/youtube/v3/liveChat/messages*';
const YT_VIDEOS = 'www.googleapis.com/youtube/v3/videos*';

/**
 * Responses shaped like the documented videos and liveChatMessage resources.
 *
 * @see https://developers.google.com/youtube/v3/docs/videos
 * @see https://developers.google.com/youtube/v3/live/docs/liveChatMessages
 */
function ytFixture(string $name, array $overrides = []): array
{
    return array_replace(json_decode(file_get_contents(base_path("tests/Fixtures/youtube/{$name}.json")), true), $overrides);
}

/** A Google API error body, as the API sends it. */
function ytError(string $reason, int $status = 403): PromiseInterface
{
    return Http::response(['error' => [
        'code' => $status,
        'message' => "The request failed: {$reason}.",
        'errors' => [['domain' => 'youtube.liveChat', 'reason' => $reason, 'message' => $reason]],
    ]], $status);
}

function ytPoll(YouTubeLiveChat $chat): void
{
    (new PollYouTubeLiveChat($chat->id))->handle(app(ChatCommandRegistry::class));
}

function ytChat(array $attributes = []): YouTubeLiveChat
{
    return YouTubeLiveChat::factory()->create($attributes + [
        'video_id' => 'edosLive001',
        'channel_id' => 'UCedosMainChannel00000000',
        'live_chat_id' => 'Cg0KC2Vkb3NMaXZlMDAxKicKGFVDZWRvc01haW5DaGFubmVsMDAwMDAwMDA',
        'started_at' => now(),
        'next_poll_at' => now(),
    ]);
}

beforeEach(function () {
    config([
        'services.youtube.api_key' => 'yt-test-key',
        'services.youtube.channel_ids' => ['UCedosMainChannel00000000', 'UCedosSecondChannel000000'],
        'services.youtube.poll_floor_ms' => 3000,
        'services.youtube.quota.daily_units' => 10000,
        'services.youtube.quota.daily_search_calls' => 100,
        'services.youtube.quota.alert_ratio' => 0.8,
    ]);
    $this->travelTo(Carbon::parse('2026-10-04T19:00:00Z'));
});

// --- Starting: one videos.list call for both channels ------------------------------

test('youtube:chat finds both live chats with one videos.list call and starts polling them', function () {
    Queue::fake();
    Http::fake([YT_VIDEOS => Http::response(ytFixture('videos.list'))]);

    $this->artisan('youtube:chat', ['videos' => ['https://www.youtube.com/watch?v=edosLive001&t=3', 'https://youtu.be/edosLive002']])
        ->expectsOutputToContain('edosLive001: reading chat')
        ->expectsOutputToContain('edosLive002: reading chat')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['id'] === 'edosLive001,edosLive002'
        && $r['part'] === 'snippet,liveStreamingDetails'
        && $r->hasHeader('X-Goog-Api-Key', 'yt-test-key')
        && ! str_contains($r->url(), 'yt-test-key'));

    expect(YouTubeLiveChat::polling()->orderBy('video_id')->pluck('channel_id')->all())
        ->toBe(['UCedosMainChannel00000000', 'UCedosSecondChannel000000'])
        ->and(YouTubeLiveChat::firstWhere('video_id', 'edosLive001')->live_chat_id)->toBe('Cg0KC2Vkb3NMaXZlMDAxKicKGFVDZWRvc01haW5DaGFubmVsMDAwMDAwMDA')
        ->and(Quota::used(Quota::UNITS))->toBe(1);
    Queue::assertPushed(PollYouTubeLiveChat::class, 2);
});

test('youtube:chat refuses a video from a channel this app does not serve', function () {
    Queue::fake();
    config(['services.youtube.channel_ids' => ['UCedosMainChannel00000000']]);
    Http::fake([YT_VIDEOS => Http::response(ytFixture('videos.list'))]);

    $this->artisan('youtube:chat', ['videos' => ['edosLive001', 'edosLive002']])
        ->expectsOutputToContain('edosLive002: not started, because it belongs to channel UCedosSecondChannel000000')
        ->assertFailed();

    expect(YouTubeLiveChat::pluck('video_id')->all())->toBe(['edosLive001']);
    Queue::assertPushed(PollYouTubeLiveChat::class, 1);
});

test('youtube:chat refuses a video that is not live or has no chat, and one that does not exist', function () {
    Queue::fake();
    $videos = ytFixture('videos.list');
    unset($videos['items'][1]['liveStreamingDetails']['activeLiveChatId']);
    Http::fake([YT_VIDEOS => Http::response($videos)]);

    $this->artisan('youtube:chat', ['videos' => ['edosLive002', 'missingVid0']])
        ->expectsOutputToContain('edosLive002: not started, because it is not live')
        ->expectsOutputToContain('missingVid0: not started, because no such video')
        ->assertFailed();

    expect(YouTubeLiveChat::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('youtube:chat needs an API key and makes no call without one', function () {
    Http::fake();
    config(['services.youtube.api_key' => null]);

    $this->artisan('youtube:chat', ['videos' => ['edosLive001']])->assertFailed();

    Http::assertNothingSent();
});

test('youtube:chat --stop stops polling', function () {
    $one = ytChat();
    $two = ytChat(['video_id' => 'edosLive002']);

    $this->artisan('youtube:chat', ['videos' => ['edosLive001'], '--stop' => true])->assertSuccessful();
    expect($one->fresh()->status)->toBe(YouTubeLiveChat::STOPPED)
        ->and($two->fresh()->status)->toBe(YouTubeLiveChat::POLLING);

    $this->artisan('youtube:chat', ['--stop' => true])->assertSuccessful();
    expect($two->fresh()->status)->toBe(YouTubeLiveChat::STOPPED);
});

test('video ids are read from bare ids and the usual URLs', function (string $input) {
    expect(YouTubeChat::videoId($input))->toBe('edosLive001');
})->with([
    'edosLive001',
    'https://www.youtube.com/watch?v=edosLive001',
    'https://www.youtube.com/watch?feature=share&v=edosLive001',
    'https://youtu.be/edosLive001?si=abc',
    'https://www.youtube.com/live/edosLive001?feature=shared',
    'https://youtube.com/shorts/edosLive001',
]);

// --- Polling: chat commands, attributed to the YouTube identity ----------------------

test('a YouTube !q lands in the same queue as Twitch questions, attributed to the YouTube user', function () {
    Queue::fake();
    $viewer = User::factory()->youtube('UCviewerYouTube0000000000')->create();
    $twitchViewer = User::factory()->twitch('4145994')->create();
    app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', '4145994', 'viewer32', 'twitch-msg', '!q a Twitch question');
    Http::fake([YT_MESSAGES => Http::response(ytFixture('liveChatMessages.list'))]);
    $chat = ytChat();

    ytPoll($chat);

    expect(Question::orderBy('id')->get(['user_id', 'question'])->toArray())->toBe([
        ['user_id' => $twitchViewer->id, 'question' => 'a Twitch question'],
        ['user_id' => $viewer->id, 'question' => 'sing about kale on YouTube'],
    ]);
    // The backlog from before start, the Super Chat and the unlinked stranger ran nothing.
    expect(ChatCommandRun::where('provider', 'youtube')->orderBy('message_id')->pluck('status', 'message_id')->all())
        ->toBe(['LCC.q-from-youtube' => 'done', 'LCC.stranger' => 'unlinked']);
});

test('a poll asks for the next page, keeps its place and queues the next poll at YouTube\'s interval', function () {
    Queue::fake();
    Http::fake([YT_MESSAGES => Http::response(ytFixture('liveChatMessages.list'))]);
    $chat = ytChat(['next_page_token' => 'previous-token']);

    ytPoll($chat);

    Http::assertSent(fn (Request $r) => $r['liveChatId'] === $chat->live_chat_id
        && $r['pageToken'] === 'previous-token'
        && $r['part'] === 'snippet,authorDetails'
        && $r->hasHeader('X-Goog-Api-Key', 'yt-test-key'));

    $chat->refresh();
    expect($chat->next_page_token)->toBe('GIDw5pDy8YgDIKKF7JDy8YgD')
        ->and($chat->poll_interval_ms)->toBe(5113)
        // Rounded up to the next whole second, never down below YouTube's interval.
        ->and($chat->next_poll_at->equalTo(now()->addSeconds(6)))->toBeTrue()
        ->and(Quota::used(Quota::UNITS))->toBe(1);
    Queue::assertPushed(PollYouTubeLiveChat::class, fn ($job) => $job->chatId === $chat->id && $job->delay->equalTo(now()->addSeconds(6)));
});

test('re-reading the same page after a retry runs no command twice', function () {
    Queue::fake();
    User::factory()->youtube('UCviewerYouTube0000000000')->create();
    Http::fake([YT_MESSAGES => Http::response(ytFixture('liveChatMessages.list'))]);
    $chat = ytChat();

    ytPoll($chat);
    $chat->refresh()->update(['next_poll_at' => now(), 'next_page_token' => null]);
    ytPoll($chat);

    expect(Question::count())->toBe(1);
});

test('polling never goes faster than the floor', function () {
    Queue::fake();
    Http::fake([YT_MESSAGES => Http::response(ytFixture('liveChatMessages.list', ['pollingIntervalMillis' => 1000]))]);
    $chat = ytChat();

    ytPoll($chat);

    expect($chat->fresh()->poll_interval_ms)->toBe(3000);
});

test('rateLimitExceeded doubles the wait, up to a minute', function () {
    Queue::fake();
    Http::fake([YT_MESSAGES => ytError('rateLimitExceeded')]);
    $chat = ytChat(['poll_interval_ms' => 5000]);

    ytPoll($chat);
    expect($chat->fresh()->poll_interval_ms)->toBe(10000);

    $chat->refresh()->update(['poll_interval_ms' => 40000, 'next_poll_at' => now()]);
    ytPoll($chat);
    expect($chat->fresh()->poll_interval_ms)->toBe(60000)
        ->and($chat->fresh()->status)->toBe(YouTubeLiveChat::POLLING)
        ->and(Quota::used(Quota::UNITS))->toBe(2)
        ->and(Quota::failedCalls(Quota::UNITS))->toBe(2);
    // The fake queue never starts the first successor, so its unique lock still
    // drops the second; on a real worker the lock is released when a job starts.
    Queue::assertPushed(PollYouTubeLiveChat::class, 1);
});

test('the chat ends on liveChatEnded and the like, and polls no more', function (string $reason, int $status) {
    Queue::fake();
    Http::fake([YT_MESSAGES => ytError($reason, $status)]);
    $chat = ytChat();

    ytPoll($chat);

    expect($chat->fresh())
        ->status->toBe(YouTubeLiveChat::ENDED)
        ->end_reason->toBe($reason)
        ->next_poll_at->toBeNull();
    Queue::assertNothingPushed();
})->with([
    ['liveChatEnded', 403],
    ['liveChatDisabled', 403],
    ['liveChatNotFound', 404],
    ['forbidden', 403],
]);

test('a response with offlineAt runs its messages, then ends the chat', function () {
    Queue::fake();
    User::factory()->youtube('UCviewerYouTube0000000000')->create();
    Http::fake([YT_MESSAGES => Http::response(ytFixture('liveChatMessages.list', ['offlineAt' => '2026-10-04T19:00:05Z']))]);
    $chat = ytChat();

    ytPoll($chat);

    expect(Question::count())->toBe(1)
        ->and($chat->fresh()->status)->toBe(YouTubeLiveChat::ENDED)
        ->and($chat->fresh()->end_reason)->toBe('offline');
    Queue::assertNothingPushed();
});

test('quotaExceeded waits for the quota to reset at midnight Pacific Time', function () {
    Queue::fake();
    Http::fake([YT_MESSAGES => ytError('quotaExceeded')]);
    $chat = ytChat();

    ytPoll($chat);

    // 19:00 UTC on 4 October is 12:00 PDT; the reset is 00:00 PDT on 5 October, 07:00 UTC.
    expect($chat->fresh()->next_poll_at->toIso8601ZuluString())->toBe('2026-10-05T07:01:00Z');
    Queue::assertPushed(PollYouTubeLiveChat::class, 1);
});

test('other failures back off exponentially and keep polling', function () {
    Queue::fake();
    Http::fake([YT_MESSAGES => Http::sequence()->push(['error' => ['code' => 500]], 500)->push(['error' => ['code' => 503]], 503)]);
    $chat = ytChat();

    ytPoll($chat);
    expect($chat->fresh()->consecutive_errors)->toBe(1)
        ->and($chat->fresh()->next_poll_at->equalTo(now()->addMilliseconds(6000)))->toBeTrue();

    $chat->refresh()->update(['next_poll_at' => now()]);
    ytPoll($chat);
    expect($chat->fresh()->consecutive_errors)->toBe(2)
        ->and($chat->fresh()->next_poll_at->equalTo(now()->addMilliseconds(12000)))->toBeTrue();
});

test('a connection failure is charged to the quota and retried later', function () {
    Queue::fake();
    Http::fake([YT_MESSAGES => fn () => throw new ConnectionException('timed out')]);
    $chat = ytChat();

    ytPoll($chat);

    expect($chat->fresh()->consecutive_errors)->toBe(1)
        ->and(Quota::used(Quota::UNITS))->toBe(1)
        ->and(Quota::failedCalls(Quota::UNITS))->toBe(1);
    Queue::assertPushed(PollYouTubeLiveChat::class, 1);
});

test('a surplus copy of the job, or a stopped chat, makes no call', function () {
    Queue::fake();
    Http::fake();

    ytPoll(ytChat(['next_poll_at' => now()->addSeconds(4)]));
    ytPoll(ytChat(['video_id' => 'edosLive002', 'status' => YouTubeLiveChat::STOPPED]));

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('one chain per chat: a second dispatch while one is queued is dropped', function () {
    Queue::fake();
    $chat = ytChat();

    PollYouTubeLiveChat::dispatch($chat->id);
    PollYouTubeLiveChat::dispatch($chat->id);

    Queue::assertPushed(PollYouTubeLiveChat::class, 1);
});

test('the watchdog restarts only chats whose poll is overdue', function () {
    Queue::fake();
    $stalled = ytChat(['next_poll_at' => now()->subMinutes(5)]);
    ytChat(['video_id' => 'edosLive002', 'next_poll_at' => now()->subSeconds(10)]);
    ytChat(['video_id' => 'edosLive003', 'status' => YouTubeLiveChat::ENDED, 'next_poll_at' => now()->subHour()]);

    expect(YouTubeLiveChat::resumeStalled())->toBe(1);
    Queue::assertPushed(PollYouTubeLiveChat::class, fn ($job) => $job->chatId === $stalled->id);
});

// --- Quota metric ----------------------------------------------------------------------

test('quota is counted per Pacific Time day', function () {
    $this->travelTo(Carbon::parse('2026-10-05T06:59:00Z')); // 23:59 PDT on 4 October
    Quota::charge('videos.list');
    $this->travelTo(Carbon::parse('2026-10-05T07:00:00Z')); // 00:00 PDT on 5 October
    Quota::charge('videos.list');
    Quota::charge('videos.list');

    expect(Quota::used(Quota::UNITS, '2026-10-04'))->toBe(1)
        ->and(Quota::used(Quota::UNITS, '2026-10-05'))->toBe(2)
        ->and(Quota::day())->toBe('2026-10-05');
});

test('the static cost table charges 50 for an insert, and search.list to its own bucket', function () {
    Quota::charge('liveChatMessages.insert');
    Quota::charge('search.list');

    expect(Quota::used(Quota::UNITS))->toBe(50)
        ->and(Quota::used(Quota::SEARCH))->toBe(1);
    expect(fn () => Quota::charge('activities.list'))->toThrow(InvalidArgumentException::class);
});

test('crossing 80% of a bucket alerts once a day', function () {
    Event::fake([YouTubeQuotaThresholdReached::class]);
    config(['services.youtube.quota.daily_units' => 10, 'services.youtube.quota.daily_search_calls' => 5]);

    foreach (range(1, 7) as $i) {
        Quota::charge('liveChatMessages.list');
    }
    Event::assertNotDispatched(YouTubeQuotaThresholdReached::class);

    Quota::charge('liveChatMessages.list'); // 8 of 10
    Quota::charge('liveChatMessages.list');
    Quota::charge('liveChatMessages.list');
    Event::assertDispatchedTimes(YouTubeQuotaThresholdReached::class, 1);
    Event::assertDispatched(YouTubeQuotaThresholdReached::class, fn ($e) => $e->bucket === 'units' && $e->used === 8 && $e->limit === 10 && $e->day === '2026-10-04');

    foreach (range(1, 4) as $i) {
        Quota::charge('search.list'); // 4 of 5 = 80%
    }
    Event::assertDispatched(YouTubeQuotaThresholdReached::class, fn ($e) => $e->bucket === 'search' && $e->used === 4);
});

test('a 50-unit call that jumps past 80% still alerts', function () {
    Event::fake([YouTubeQuotaThresholdReached::class]);
    config(['services.youtube.quota.daily_units' => 100]);

    Quota::charge('liveChatMessages.insert');
    Quota::charge('liveChatMessages.insert');

    Event::assertDispatched(YouTubeQuotaThresholdReached::class, fn ($e) => $e->used === 100);
});

test('the quota alert is logged as a warning', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, '8000 of 10000 units used on 2026-10-04'));

    (new AlertOnYouTubeQuota)->handle(new YouTubeQuotaThresholdReached('units', 8000, 10000, '2026-10-04'));
});

test('youtube:quota shows the day\'s use and the burn rate of the running polls', function () {
    Quota::charge('videos.list');
    ytChat(['poll_interval_ms' => 5000]);
    ytChat(['video_id' => 'edosLive002', 'poll_interval_ms' => 5000]);

    $this->artisan('youtube:quota')
        ->expectsOutputToContain('Day (PT): 2026-10-04')
        ->expectsOutputToContain('about 1440 units an hour')
        ->assertSuccessful();
});
