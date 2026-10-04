<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Clips\ClipHelix;
use App\Clips\StreamMarkerStatus;
use App\IdentityProvider;
use App\Jobs\Clips\CreateClipForMarker;
use App\Jobs\Clips\FetchClipFile;
use App\Jobs\EventSub\HandleChatMessage;
use App\Jobs\PostChatReply;
use App\Models\BroadcasterToken;
use App\Models\StreamMarker;
use App\Models\StreamSession;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Twitch;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;

// TestCase serves channels 1000 (primary) and 2000.

// Replies to chat (#89) are queued; keep them off Helix so each test sees only
// the clip pipeline's own calls. The reply itself is asserted where it matters.
// FetchClipFile has its own tests (ClipApprovalTest). No test may reach the network.
beforeEach(function () {
    Queue::fake([PostChatReply::class, FetchClipFile::class]);
    Http::preventStrayRequests();
});

function clipBroadcasterToken(string $id = '1000', ?array $scopes = null): BroadcasterToken
{
    return BroadcasterToken::create([
        'broadcaster_id' => $id,
        'access_token' => 'access-'.$id,
        'refresh_token' => 'refresh-'.$id,
        'expires_at' => now()->addHours(4),
        'scopes' => $scopes ?? Twitch::BROADCASTER_SCOPES,
    ]);
}

function clipModerator(string $twitchId = '7777', string $channel = '1000'): User
{
    $mod = User::factory()->twitch($twitchId)->create();
    TwitchModerator::create(['broadcaster_id' => $channel, 'twitch_user_id' => $twitchId]);

    return $mod;
}

function runClip(string $text, string $chatterId = '7777', string $channel = '1000', string $name = 'modname'): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, $channel, $chatterId, $name, (string) Str::uuid(), $text);
}

/** Twitch answers every marker request with a new marker id: marker-1, marker-2, ... */
function fakeMarker(int $position = 600): void
{
    $n = 0;
    Http::fake([
        'api.twitch.tv/helix/streams/markers' => function () use (&$n, $position) {
            $n++;

            return Http::response(['data' => [[
                'id' => 'marker-'.$n,
                'created_at' => now()->toIso8601ZuluString(),
                'description' => 'x',
                'position_seconds' => $position,
            ]]]);
        },
    ]);
}

/** Create Clip From VOD takes everything in the query string. */
function clipQuery(Request $r): array
{
    parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

    return $q;
}

/** Run the clip job once, with the queue interactions faked, and return it for release assertions. */
function runClipJob(StreamMarker $marker): CreateClipForMarker
{
    $job = (new CreateClipForMarker($marker->id))->withFakeQueueInteractions();
    $job->handle(app(ClipHelix::class));

    return $job;
}

function helixError(int $status, string $message): PromiseInterface
{
    return Http::response(['error' => 'x', 'status' => $status, 'message' => $message], $status);
}

function clipsFragment(): Testable
{
    return Livewire::test(FragmentAlias::encode('clips', resource_path('views/clips.blade.php')));
}

// --- !clip: who may run it -----------------------------------------------------

test('a viewer cannot !clip, and Twitch is never called', function () {
    Http::fake();
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    User::factory()->twitch('4145994')->create();

    $result = runClip('!clip nice', chatterId: '4145994');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toBe('Only moderators of this channel can use !clip.')
        ->and(StreamMarker::count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNotPushed(CreateClipForMarker::class);
});

test('an unlinked chatter cannot !clip', function () {
    Http::fake();

    expect(runClip('!clip', chatterId: '999999')->status)->toBe(ChatCommandStatus::Unlinked);
    Http::assertNothingSent();
});

test('a moderator banned here cannot !clip', function () {
    Http::fake();
    clipBroadcasterToken();
    clipModerator()->localBans()->create(['reason' => 'spam']);

    expect(runClip('!clip')->status)->toBe(ChatCommandStatus::Banned);
    Http::assertNothingSent();
});

test('a moderator\'s !clip creates a Helix marker with the broadcaster\'s token and queues the clip', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    $mod = clipModerator();
    $session = StreamSession::factory()->create(['broadcaster_id' => '1000', 'twitch_stream_id' => '40000001']);
    StreamSession::factory()->create(['broadcaster_id' => '2000']);
    fakeMarker(position: 3725);

    $result = runClip('!clip   that drop  ');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toContain('1:02:05');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.twitch.tv/helix/streams/markers'
        && $r->hasHeader('Authorization', 'Bearer access-1000')
        && $r->hasHeader('Client-ID', 'client-id')
        && $r['user_id'] === '1000'
        && $r['description'] === 'that drop');

    $marker = StreamMarker::sole();
    expect($marker->stream_session_id)->toBe($session->id)
        ->and($marker->broadcaster_id)->toBe('1000')
        ->and($marker->twitch_marker_id)->toBe('marker-1')
        ->and($marker->position_seconds)->toBe(3725)
        ->and($marker->description)->toBe('that drop')
        ->and($marker->created_by_user_id)->toBe($mod->id)
        ->and($marker->status)->toBe(StreamMarkerStatus::ClipPending);

    Queue::assertPushed(CreateClipForMarker::class, fn (CreateClipForMarker $job) => $job->markerId === $marker->id && $job->delay !== null);
});

test('the broadcaster can !clip their own channel', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    User::factory()->twitch('1000')->create();
    fakeMarker();

    expect(runClip('!clip', chatterId: '1000')->status)->toBe(ChatCommandStatus::Done)
        ->and(StreamMarker::sole()->status)->toBe(StreamMarkerStatus::ClipPending);
});

test('!clip runs from Twitch chat through the queued chat job, once per message', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    clipModerator();
    fakeMarker();

    $event = [
        'broadcaster_user_id' => '1000',
        'chatter_user_id' => '7777',
        'chatter_user_name' => 'modname',
        'message_id' => 'chat-msg-1',
        'message' => ['text' => '!clip from chat', 'fragments' => []],
        'message_type' => 'text',
        'badges' => [['set_id' => 'moderator']],
    ];

    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), $event))->handle();
    // Twitch redelivers the same chat message under a new EventSub message id.
    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), $event))->handle();

    expect(StreamMarker::sole()->description)->toBe('from chat');
    Http::assertSentCount(1);
    Queue::assertPushed(CreateClipForMarker::class, 1);
});

test('without a note the marker says who clipped it, and a long note is cut to 140 characters', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    clipModerator();
    fakeMarker();

    runClip('!clip', name: 'ModName');
    runClip('!clip '.str_repeat('é', 200)."\u{202E}");

    [$first, $second] = StreamMarker::orderBy('id')->get()->all();
    expect($first->description)->toBe('!clip by ModName')
        ->and($second->description)->toBe(str_repeat('é', 140));
    Http::assertSent(fn (Request $r) => $r['description'] === str_repeat('é', 140));
});

test('!clip answers in chat: the position to a mod, a refusal to a viewer', function () {
    clipBroadcasterToken();
    clipModerator();
    User::factory()->twitch('4145994')->create();
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    fakeMarker(position: 125);

    runClip('!clip');
    runClip('!clip', chatterId: '4145994');

    Queue::assertPushed(PostChatReply::class, fn (PostChatReply $job) => $job->channelId === '1000' && $job->reply === 'Marked at 0:02:05. Clipping the minute around it.');
    Queue::assertPushed(PostChatReply::class, fn (PostChatReply $job) => $job->reply === 'Only moderators of this channel can use !clip.');
});

test('a moderator of channel B cannot !clip channel A, but can !clip B', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken('1000');
    clipBroadcasterToken('2000');
    clipModerator('8888', channel: '2000');
    fakeMarker();

    $onA = runClip('!clip on A', chatterId: '8888', channel: '1000');
    expect($onA->status)->toBe(ChatCommandStatus::Rejected)
        ->and($onA->reply)->toBe('Only moderators of this channel can use !clip.')
        ->and(StreamMarker::count())->toBe(0);
    Http::assertNothingSent();

    expect(runClip('!clip on B', chatterId: '8888', channel: '2000')->status)->toBe(ChatCommandStatus::Done);
    expect(StreamMarker::sole()->broadcaster_id)->toBe('2000');
    Http::assertSent(fn (Request $r) => $r['user_id'] === '2000' && $r->hasHeader('Authorization', 'Bearer access-2000'));
    Queue::assertPushed(CreateClipForMarker::class, 1);
});

test('the broadcaster of A can !clip A, and only A', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken('1000');
    clipBroadcasterToken('2000');
    User::factory()->twitch('1000')->create();
    fakeMarker();

    expect(runClip('!clip mine', chatterId: '1000', channel: '1000')->status)->toBe(ChatCommandStatus::Done)
        ->and(runClip('!clip theirs', chatterId: '1000', channel: '2000')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(StreamMarker::sole()->broadcaster_id)->toBe('1000');
});

// --- !clip: rate limit ---------------------------------------------------------

test('!clip is rate-limited per channel across mods, and viewers do not use up the budget', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    config(['clips.per_channel_per_minute' => 2]);
    clipBroadcasterToken('1000');
    clipBroadcasterToken('2000');
    clipModerator('7777');
    clipModerator('8888');
    TwitchModerator::create(['broadcaster_id' => '2000', 'twitch_user_id' => '8888']); // moderates both channels
    User::factory()->twitch('4145994')->create();
    fakeMarker();

    runClip('!clip', chatterId: '4145994');
    expect(runClip('!clip one', chatterId: '7777')->status)->toBe(ChatCommandStatus::Done)
        ->and(runClip('!clip two', chatterId: '8888')->status)->toBe(ChatCommandStatus::Done);

    $limited = runClip('!clip three', chatterId: '7777');
    expect($limited->status)->toBe(ChatCommandStatus::Rejected)
        ->and($limited->reply)->toContain('Too many clips on this channel');

    // Another channel has its own budget.
    expect(runClip('!clip elsewhere', chatterId: '8888', channel: '2000')->status)->toBe(ChatCommandStatus::Done)
        ->and(StreamMarker::count())->toBe(3);

    $this->travel(61)->seconds();
    expect(runClip('!clip four', chatterId: '7777')->status)->toBe(ChatCommandStatus::Done);
});

// --- !clip: Twitch refuses the marker ------------------------------------------

test('a marker Twitch refuses because VOD storage is off or the channel is offline is kept as marker_failed', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    clipModerator();
    Http::fake(['api.twitch.tv/helix/streams/markers' => helixError(404, "The user in the user_id field is not streaming live. The ID in the user_id field is not valid. The user hasn't enabled video on demand (VOD).")]);

    $result = runClip('!clip nope');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toContain('Store past broadcasts');

    $marker = StreamMarker::sole();
    expect($marker->status)->toBe(StreamMarkerStatus::MarkerFailed)
        ->and($marker->twitch_marker_id)->toBeNull()
        ->and($marker->error)->toContain("hasn't enabled video on demand");
    Queue::assertNotPushed(CreateClipForMarker::class);
});

test('a rate-limited marker tells the mod to try again', function () {
    Queue::fake([CreateClipForMarker::class, PostChatReply::class]);
    clipBroadcasterToken();
    clipModerator();
    Http::fake(['api.twitch.tv/helix/streams/markers' => Http::response(['message' => 'Too Many Requests'], 429)]);

    expect(runClip('!clip')->reply)->toContain('rate-limiting')
        ->and(StreamMarker::sole()->status)->toBe(StreamMarkerStatus::MarkerFailed);
    Queue::assertNotPushed(CreateClipForMarker::class);
});

test('!clip refuses a channel whose broadcaster has not connected', function () {
    Http::fake();
    clipModerator();

    expect(runClip('!clip')->reply)->toContain('not connected');
    Http::assertNothingSent();
});

// --- the clip job --------------------------------------------------------------

function fakeClipHelix(array $overrides = []): void
{
    Http::fake($overrides + [
        'api.twitch.tv/helix/videos/clips*' => Http::response(['data' => [['id' => 'ClipSlug', 'edit_url' => 'https://www.twitch.tv/edos/clip/ClipSlug']]], 202),
        'api.twitch.tv/helix/videos*' => Http::response(['data' => [
            ['id' => '999', 'stream_id' => '39999999', 'type' => 'archive'],
            ['id' => '555', 'stream_id' => '40000001', 'type' => 'archive'],
        ]]),
        'api.twitch.tv/helix/clips/downloads*' => Http::response(['data' => [[
            'clip_id' => 'ClipSlug',
            'landscape_download_url' => 'https://production.assets.clips.twitchcdn.net/landscape.mp4',
            'portrait_download_url' => null,
        ]]]),
        'api.twitch.tv/helix/clips*' => Http::response(['data' => [['id' => 'ClipSlug', 'video_id' => '555']]]),
    ]);
}

function pendingMarker(array $attributes = []): StreamMarker
{
    clipBroadcasterToken();
    $session = StreamSession::factory()->create(['broadcaster_id' => '1000', 'twitch_stream_id' => '40000001']);

    return StreamMarker::factory()->create($attributes + [
        'stream_session_id' => $session->id,
        'position_seconds' => 600,
        'description' => 'that drop',
    ]);
}

test('the clip job cuts the minute ending 15 s after the marker from the live VOD, then stores the download URL', function () {
    fakeClipHelix();
    $marker = pendingMarker();

    runClipJob($marker)->assertReleased(CreateClipForMarker::POLL_SECONDS);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_starts_with($r->url(), 'https://api.twitch.tv/helix/videos?')
        && $r['user_id'] === '1000' && $r['type'] === 'archive');
    Http::assertSent(function (Request $r) {
        if ($r->method() !== 'POST' || ! str_starts_with($r->url(), 'https://api.twitch.tv/helix/videos/clips?')) {
            return false;
        }
        parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q === [
            'broadcaster_id' => '1000',
            'editor_id' => '1000',
            'vod_id' => '555',
            'vod_offset' => '615',
            'duration' => '60',
            'title' => 'that drop',
        ] && $r->hasHeader('Authorization', 'Bearer access-1000');
    });

    $marker->refresh();
    expect($marker->status)->toBe(StreamMarkerStatus::ClipProcessing)
        ->and($marker->vod_id)->toBe('555')
        ->and($marker->clip_id)->toBe('ClipSlug')
        ->and($marker->clip_edit_url)->toBe('https://www.twitch.tv/edos/clip/ClipSlug')
        ->and($marker->clip_requested_at)->not->toBeNull();

    $this->freezeTime();
    runClipJob($marker)->assertNotReleased();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.twitch.tv/helix/clips?') && $r['id'] === 'ClipSlug');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.twitch.tv/helix/clips/downloads?')
        && $r['broadcaster_id'] === '1000' && $r['editor_id'] === '1000' && $r['clip_id'] === 'ClipSlug');

    $marker->refresh();
    expect($marker->status)->toBe(StreamMarkerStatus::ClipReady)
        ->and($marker->error)->toBeNull()
        ->and($marker->landscape_download_url)->toBe('https://production.assets.clips.twitchcdn.net/landscape.mp4')
        ->and($marker->portrait_download_url)->toBeNull()
        ->and($marker->download_urls_expire_at->getTimestamp())->toBe(now()->addSeconds(config('clips.download_url_ttl_seconds'))->getTimestamp());

    // The URLs are temporary, so the files are fetched straight away.
    Queue::assertPushed(FetchClipFile::class, fn (FetchClipFile $job) => $job->markerId === $marker->id);
});

test('a marker in the first minute of the stream gets a shorter clip, since vod_offset must cover the duration', function () {
    fakeClipHelix();
    runClipJob(pendingMarker(['position_seconds' => 20]));

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && clipQuery($r)['vod_offset'] === '35' && clipQuery($r)['duration'] === '35');
});

test('a signed download URL\'s own expiry is stored', function () {
    $expires = now()->addMinutes(10)->getTimestamp();
    fakeClipHelix([
        'api.twitch.tv/helix/clips/downloads*' => Http::response(['data' => [[
            'clip_id' => 'ClipSlug',
            'landscape_download_url' => 'https://production.assets.clips.twitchcdn.net/l.mp4?Expires='.($expires + 600).'&Signature=abc',
            'portrait_download_url' => 'https://production.assets.clips.twitchcdn.net/p.mp4?Expires='.$expires.'&Signature=def',
        ]]]),
    ]);
    $marker = pendingMarker(['clip_id' => 'ClipSlug', 'vod_id' => '555', 'clip_requested_at' => now(), 'status' => StreamMarkerStatus::ClipProcessing]);

    runClipJob($marker);

    $marker->refresh();
    expect($marker->status)->toBe(StreamMarkerStatus::ClipReady)
        ->and($marker->portrait_download_url)->toContain('p.mp4')
        ->and($marker->download_urls_expire_at->getTimestamp())->toBe($expires);
});

test('S3-style signed URLs are understood too', function () {
    $expiry = CreateClipForMarker::expiry(['https://x.example/c.mp4?X-Amz-Date=20261004T230000Z&X-Amz-Expires=900&X-Amz-Signature=s']);

    expect($expiry->toIso8601ZuluString())->toBe('2026-10-04T23:15:00Z');
});

test('the job polls Get Clips until the clip exists, and gives up 60 s after creating it', function () {
    fakeClipHelix(['api.twitch.tv/helix/clips*' => Http::response(['data' => []])]);
    $marker = pendingMarker(['clip_id' => 'ClipSlug', 'vod_id' => '555', 'clip_requested_at' => now(), 'status' => StreamMarkerStatus::ClipProcessing]);

    $this->travel(30)->seconds();
    runClipJob($marker)->assertReleased(CreateClipForMarker::POLL_SECONDS);
    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipProcessing);

    $this->travel(31)->seconds();
    runClipJob($marker)->assertNotReleased();

    $marker->refresh();
    expect($marker->status)->toBe(StreamMarkerStatus::ClipFailed)
        ->and($marker->error)->toContain('within 60 s')
        ->and($marker->clip_id)->toBe('ClipSlug');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/clips/downloads'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

test('a clip with no download URL yet is polled again, then fails at the deadline', function () {
    fakeClipHelix(['api.twitch.tv/helix/clips/downloads*' => Http::response(['data' => [['clip_id' => 'ClipSlug', 'landscape_download_url' => null, 'portrait_download_url' => null]]])]);
    $marker = pendingMarker(['clip_id' => 'ClipSlug', 'vod_id' => '555', 'clip_requested_at' => now(), 'status' => StreamMarkerStatus::ClipProcessing]);

    runClipJob($marker)->assertReleased(CreateClipForMarker::POLL_SECONDS);

    $this->travel(61)->seconds();
    runClipJob($marker);

    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipFailed)
        ->and($marker->error)->toContain('no download URL');
});

// --- the clip job: failures from spike #25 -------------------------------------

test('no VOD of the live stream means Store past broadcasts is off', function () {
    fakeClipHelix(['api.twitch.tv/helix/videos*' => Http::response(['data' => [['id' => '999', 'stream_id' => '39999999']]])]);
    $marker = pendingMarker();

    runClipJob($marker)->assertNotReleased();

    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipFailed)
        ->and($marker->error)->toContain('Store past broadcasts');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

test('without a stream session the newest archive is clipped', function () {
    fakeClipHelix();
    clipBroadcasterToken();
    $marker = StreamMarker::factory()->create(['stream_session_id' => null, 'position_seconds' => 600]);

    runClipJob($marker);

    expect($marker->refresh()->vod_id)->toBe('999');
});

test('each Create Clip From VOD refusal fails the marker with a reason, and is not retried', function (int $status, string $twitchSays, string $expected) {
    fakeClipHelix(['api.twitch.tv/helix/videos/clips*' => helixError($status, $twitchSays)]);
    $marker = pendingMarker();

    runClipJob($marker)->assertNotReleased();

    $marker->refresh();
    expect($marker->status)->toBe(StreamMarkerStatus::ClipFailed)
        ->and($marker->clip_id)->toBeNull()
        ->and($marker->error)->toContain($expected)
        ->and($marker->error)->toContain('Twitch said: '.$twitchSays);
})->with([
    'not live' => [404, 'The broadcaster in the broadcaster_id query parameter must be broadcasting live.', 'no longer live'],
    'VOD not found' => [404, 'The VOD is not found..', 'Store past broadcasts'],
    'not clippable' => [400, 'The category is not clippable.', 'current category'],
    'clips restricted' => [403, 'The broadcaster has restricted the ability to capture clips to followers and/or subscribers only.', 'clips may be off'],
    'missing scope' => [401, 'The user access token must include the editor:manage:clips or channel:manage:clips scope.', 'reconnect'],
]);

test('a title that fails AutoMod is retried once with a neutral title', function () {
    Http::fake([
        'api.twitch.tv/helix/videos/clips*' => Http::sequence()
            ->push(['message' => 'The title did not pass AutoMod checks.'], 400)
            ->push(['data' => [['id' => 'ClipSlug', 'edit_url' => 'https://www.twitch.tv/edos/clip/ClipSlug']]], 202),
        'api.twitch.tv/helix/videos*' => Http::response(['data' => [['id' => '555', 'stream_id' => '40000001']]]),
    ]);
    $marker = pendingMarker(['description' => 'something AutoMod hates']);

    runClipJob($marker);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && clipQuery($r)['title'] === CreateClipForMarker::FALLBACK_TITLE);
    expect($marker->refresh()->clip_id)->toBe('ClipSlug');
});

test('a 429 from Twitch releases the job until the rate limit resets, without failing the marker', function () {
    $this->freezeTime();
    fakeClipHelix(['api.twitch.tv/helix/videos/clips*' => Http::response(['message' => 'Too Many Requests'], 429, ['Ratelimit-Reset' => (string) (now()->getTimestamp() + 12)])]);
    $marker = pendingMarker();

    runClipJob($marker)->assertReleased(12);

    $marker->refresh();
    expect($marker->status)->toBe(StreamMarkerStatus::ClipPending)
        ->and($marker->clip_id)->toBeNull()
        ->and($marker->vod_id)->toBe('555');
});

test('a Twitch 5xx throws so the queue retries with backoff', function () {
    fakeClipHelix(['api.twitch.tv/helix/videos/clips*' => Http::response(['message' => 'oops'], 503)]);
    $marker = pendingMarker();

    expect(fn () => runClipJob($marker))->toThrow(RequestException::class);
    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipPending);
});

test('when the job finally gives up, the marker shows it failed', function () {
    $marker = pendingMarker();

    (new CreateClipForMarker($marker->id))->failed(new RuntimeException('boom'));

    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipFailed)
        ->and($marker->error)->toContain('Gave up');
});

test('a broadcaster who has not granted channel:manage:clips gets a reconnect message, and Twitch is not called', function () {
    Http::fake();
    $marker = pendingMarker();
    // A token granted before this change: every scope except channel:manage:clips.
    BroadcasterToken::sole()->update(['scopes' => array_values(array_diff(Twitch::BROADCASTER_SCOPES, ['channel:manage:clips']))]);

    runClipJob($marker);

    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipFailed)
        ->and($marker->error)->toContain('channel:manage:clips');
    Http::assertNothingSent();
});

test('the broadcaster connection asks for channel:manage:clips', function () {
    expect(Twitch::BROADCASTER_SCOPES)->toContain('channel:manage:clips', 'channel:manage:broadcast');
});

// --- the clip job: idempotency -------------------------------------------------

test('a retry after the clip was created never creates a second clip', function () {
    fakeClipHelix();
    $marker = pendingMarker();

    runClipJob($marker);
    runClipJob($marker);
    runClipJob($marker);

    Http::assertSentCount(1 + 1 + 2);   // videos, create clip, then get clip + downloads once
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'))->toHaveCount(1)
        ->and($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipReady);
});

test('the queued job runs end to end, and a duplicate dispatch does nothing more', function () {
    fakeClipHelix();
    $marker = pendingMarker(['clip_id' => 'ClipSlug', 'vod_id' => '555', 'clip_requested_at' => now(), 'status' => StreamMarkerStatus::ClipProcessing]);

    CreateClipForMarker::dispatch($marker->id);
    CreateClipForMarker::dispatch($marker->id);

    expect($marker->refresh()->status)->toBe(StreamMarkerStatus::ClipReady);
    Http::assertSentCount(2);
});

test('the job ignores a marker that Twitch never created, or that is gone', function () {
    Http::fake();
    clipBroadcasterToken();
    $failed = StreamMarker::factory()->markerFailed()->create();

    runClipJob($failed);
    (new CreateClipForMarker(987654))->withFakeQueueInteractions()->handle(app(ClipHelix::class));

    Http::assertNothingSent();
    expect($failed->refresh()->status)->toBe(StreamMarkerStatus::MarkerFailed);
});

// --- the mod page ----------------------------------------------------------------

test('guests are sent to log in, and viewers get a 403 on /clips', function () {
    $this->get('/clips')->assertRedirect();
    $this->actingAs(User::factory()->twitch('4145994')->create())->get('/clips')->assertForbidden();
});

test('a moderator sees markers and their clip status, newest first', function () {
    $mod = clipModerator();
    StreamMarker::factory()->markerFailed('Twitch would not add a marker: the channel must be live')->create(['description' => 'older one', 'created_at' => now()->subHour()]);
    StreamMarker::factory()->ready()->create(['description' => 'the big drop', 'position_seconds' => 3725, 'created_by_user_id' => $mod->id]);
    StreamMarker::factory()->ready()->create(['description' => 'not fetched yet']);

    $this->actingAs($mod)->get('/clips?show=all')
        ->assertOk()
        ->assertSeeInOrder(['not fetched yet', 'the big drop', 'older one'])
        ->assertSee('1:02:05')
        ->assertSee('Clip ready')
        ->assertSee('Downloading the file')
        ->assertSee('Marker failed')
        ->assertSee('the channel must be live');
});

test('the clips page re-checks the gate on every Livewire request', function () {
    $this->actingAs(User::factory()->twitch('4145994')->create());

    clipsFragment()->assertForbidden();
});

test('moderators get a Clips link in the nav, viewers do not', function () {
    $this->actingAs(clipModerator())->get('/vote')->assertSee(route('clips.index'));
    $this->actingAs(User::factory()->twitch('4145994')->create())->get('/vote')->assertDontSee(route('clips.index'));
});
