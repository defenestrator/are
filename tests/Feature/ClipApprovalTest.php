<?php

use App\Clips\ClipDecisionKind;
use App\Clips\ClipHelix;
use App\Clips\ClipReview;
use App\Clips\ClipReviewStatus;
use App\Clips\StreamMarkerStatus;
use App\Jobs\Clips\CreateClipForMarker;
use App\Jobs\Clips\FetchClipFile;
use App\Jobs\PostChatReply;
use App\Models\BroadcasterToken;
use App\Models\ClipDecision;
use App\Models\StreamMarker;
use App\Models\StreamSession;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Twitch;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;

// #11 slice 2: fetching clip files, the approval queue, and reusing a clip an
// earlier attempt made. TestCase serves channels 1000 and 2000.

const CDN = 'https://production.assets.clips.twitchcdn.net';

beforeEach(function () {
    Queue::fake([PostChatReply::class]);
    Http::preventStrayRequests();
    Storage::fake('local');
    Process::preventStrayProcesses(); // approving queues ffmpeg (#146); never run it for real

    BroadcasterToken::create([
        'broadcaster_id' => '1000',
        'access_token' => 'access-1000',
        'refresh_token' => 'refresh-1000',
        'expires_at' => now()->addHours(4),
        'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);
});

function approvalMod(string $twitchId = '7777'): User
{
    $mod = User::factory()->twitch($twitchId)->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $twitchId]);

    return $mod;
}

function readyClip(array $attributes = []): StreamMarker
{
    return StreamMarker::factory()->ready()->create($attributes + [
        'clip_id' => 'ClipSlug',
        'description' => 'that drop',
        'landscape_download_url' => CDN.'/landscape.mp4',
        'portrait_download_url' => null,
        'download_urls_expire_at' => now()->addMinutes(20),
    ]);
}

function fetchClip(StreamMarker $marker): FetchClipFile
{
    $job = (new FetchClipFile($marker->id))->withFakeQueueInteractions();
    $job->handle(app(ClipHelix::class));

    return $job;
}

function mp4(string $bytes = 'fake-mp4-bytes'): PromiseInterface
{
    return Http::response($bytes, 200, ['Content-Type' => 'video/mp4']);
}

function clipsPage(): Testable
{
    return Livewire::test(FragmentAlias::encode('clips', resource_path('views/clips.blade.php')));
}

// --- fetching the files ----------------------------------------------------------

test('a ready clip\'s MP4 is downloaded to the private disk, without the broadcaster token', function () {
    Http::fake([CDN.'/*' => mp4('landscape-bytes')]);
    $marker = readyClip();

    fetchClip($marker)->assertNotFailed()->assertNotReleased();

    $marker->refresh();
    $path = 'clips/'.$marker->id.'/ClipSlug-landscape.mp4';
    expect($marker->landscape_file_path)->toBe($path)
        ->and($marker->portrait_file_path)->toBeNull()
        ->and($marker->file_bytes)->toBe(strlen('landscape-bytes'))
        ->and($marker->fetched_at)->not->toBeNull()
        ->and($marker->fetch_error)->toBeNull();
    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe('landscape-bytes');

    Http::assertSent(fn (Request $r) => $r->url() === CDN.'/landscape.mp4' && ! $r->hasHeader('Authorization'));
});

test('both variants are fetched when Twitch offers a portrait one', function () {
    Http::fake([CDN.'/landscape.mp4' => mp4('L'), CDN.'/portrait.mp4' => mp4('PP')]);
    $marker = readyClip(['portrait_download_url' => CDN.'/portrait.mp4']);

    fetchClip($marker);

    $marker->refresh();
    expect($marker->portrait_file_path)->toBe('clips/'.$marker->id.'/ClipSlug-portrait.mp4')
        ->and($marker->file_bytes)->toBe(3);
    Storage::disk('local')->assertExists($marker->portrait_file_path);
});

test('fetching is idempotent: a stored file is not downloaded again', function () {
    Http::fake([CDN.'/*' => mp4()]);
    $marker = readyClip();

    fetchClip($marker);
    fetchClip($marker);
    FetchClipFile::dispatch($marker->id);

    Http::assertSentCount(1);
});

test('an expired download URL is fetched again from Twitch before downloading', function () {
    Http::fake([
        'api.twitch.tv/helix/clips/downloads*' => Http::response(['data' => [['clip_id' => 'ClipSlug', 'landscape_download_url' => CDN.'/fresh.mp4', 'portrait_download_url' => null]]]),
        CDN.'/fresh.mp4' => mp4(),
    ]);
    $marker = readyClip(['landscape_download_url' => CDN.'/stale.mp4', 'download_urls_expire_at' => now()->subMinute()]);

    fetchClip($marker);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/helix/clips/downloads') && $r->hasHeader('Authorization', 'Bearer access-1000'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'stale.mp4'));
    expect($marker->refresh()->landscape_download_url)->toBe(CDN.'/fresh.mp4')
        ->and($marker->landscape_file_path)->not->toBeNull();
});

test('a CDN refusal marks the URLs stale and retries, so the next attempt refreshes them', function () {
    Http::fake([CDN.'/*' => Http::response('denied', 403)]);
    $marker = readyClip();

    expect(fn () => fetchClip($marker))->toThrow(RuntimeException::class, 'HTTP 403');

    $marker->refresh();
    expect($marker->download_urls_expire_at->isPast() || $marker->download_urls_expire_at->isCurrentSecond())->toBeTrue()
        ->and($marker->landscape_file_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('a 429 while refreshing URLs releases the job until the limit resets', function () {
    $this->freezeTime();
    Http::fake(['api.twitch.tv/helix/clips/downloads*' => Http::response([], 429, ['Ratelimit-Reset' => (string) (now()->getTimestamp() + 7)])]);

    fetchClip(readyClip(['download_urls_expire_at' => now()->subMinute()]))->assertReleased(7);
});

test('a URL on any other host is never fetched', function () {
    Http::fake();
    $marker = readyClip(['landscape_download_url' => 'https://evil.example/landscape.mp4']);

    fetchClip($marker)->assertFailed();

    Http::assertNothingSent();
    expect($marker->refresh()->fetch_error)->toContain('evil.example')
        ->and($marker->landscape_file_path)->toBeNull();
});

test('download hosts must be HTTPS and match exactly or as a subdomain', function (string $url, bool $allowed) {
    expect(FetchClipFile::allowedUrl($url))->toBe($allowed);
})->with([
    [CDN.'/a.mp4', true],
    ['https://twitchcdn.net/a.mp4', true],
    ['http://production.assets.clips.twitchcdn.net/a.mp4', false],
    ['https://twitchcdn.net.evil.example/a.mp4', false],
    ['https://eviltwitchcdn.net/a.mp4', false],
    ['not a url', false],
]);

test('an oversized file, an empty file or a non-video answer is refused and not stored', function (array $response, string $error) {
    config(['clips.max_file_bytes' => 10]);
    Http::fake([CDN.'/*' => Http::response(...$response)]);
    $marker = readyClip();

    expect(fn () => fetchClip($marker))->toThrow(RuntimeException::class, $error);

    expect($marker->refresh()->landscape_file_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
})->with([
    'too big' => [[str_repeat('x', 11), 200, ['Content-Type' => 'video/mp4']], 'over the 10-byte limit'],
    'empty' => [['', 200, ['Content-Type' => 'video/mp4']], 'empty file'],
    'html' => [['<html>', 200, ['Content-Type' => 'text/html']], 'not a video'],
]);

// --- #148: redirects and streaming size limit ---------------------------------

test('a redirect to another CDN host is followed, with every hop checked', function () {
    Http::fake([
        CDN.'/landscape.mp4' => Http::response('', 302, ['Location' => 'https://edge.twitchcdn.net/real.mp4']),
        'https://edge.twitchcdn.net/real.mp4' => mp4('real-bytes'),
    ]);
    $marker = readyClip();

    fetchClip($marker)->assertNotFailed();

    expect(Storage::disk('local')->get($marker->refresh()->landscape_file_path))->toBe('real-bytes');
    Http::assertSentCount(2);
});

test('a relative redirect is resolved against the CDN URL', function () {
    Http::fake([
        CDN.'/landscape.mp4' => Http::response('', 301, ['Location' => '/v2/landscape.mp4']),
        CDN.'/v2/landscape.mp4' => mp4('moved'),
    ]);
    $marker = readyClip();

    fetchClip($marker);

    expect(Storage::disk('local')->get($marker->refresh()->landscape_file_path))->toBe('moved');
});

test('a redirect off the allowlist, or to plain HTTP, is refused and never requested', function (string $location) {
    Http::fake([
        CDN.'/landscape.mp4' => Http::response('', 302, ['Location' => $location]),
        '*' => mp4('should never be fetched'),
    ]);
    $marker = readyClip();

    fetchClip($marker)->assertFailed();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => $r->url() === $location);
    expect($marker->refresh()->fetch_error)->toContain('(a redirect)')
        ->and($marker->landscape_file_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
})->with([
    'another host' => ['https://evil.example/x.mp4'],
    'a lookalike host' => ['https://twitchcdn.net.evil.example/x.mp4'],
    'a downgrade to http' => ['http://production.assets.clips.twitchcdn.net/x.mp4'],
    'an internal address' => ['https://169.254.169.254/latest/meta-data'],
]);

test('more than three redirects is refused', function () {
    Http::fake([CDN.'/*' => Http::response('', 302, ['Location' => CDN.'/again.mp4'])]);
    $marker = readyClip();

    fetchClip($marker)->assertFailed();

    Http::assertSentCount(4);
    expect($marker->refresh()->fetch_error)->toContain('more than 3 times');
});

test('a Content-Length over the limit is refused before the body is read', function () {
    config(['clips.max_file_bytes' => 1000]);
    $read = 0;
    $body = new PumpStream(function () use (&$read) {
        $read += 100;

        return str_repeat('x', 100);
    });
    Http::fake([CDN.'/*' => fn () => Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => '5000'], $body))]);
    $marker = readyClip();

    expect(fn () => fetchClip($marker))->toThrow(RuntimeException::class, '5000 bytes, over the 1000-byte limit');

    expect($read)->toBe(0)
        ->and($marker->refresh()->landscape_file_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('an oversized stream with no Content-Length is cut off as it passes the limit', function () {
    config(['clips.max_file_bytes' => 3 * 1024 * 1024]);
    $read = 0;
    // Never ends: only the streaming check can stop this download.
    $body = new PumpStream(function (int $length) use (&$read) {
        $read += $length;

        return str_repeat('x', $length);
    });
    Http::fake([CDN.'/*' => fn () => Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'video/mp4'], $body))]);
    $marker = readyClip();

    expect(fn () => fetchClip($marker))->toThrow(RuntimeException::class, 'stopped downloading');

    // It stopped within one chunk (1 MB) of the limit.
    expect($read)->toBeLessThanOrEqual(4 * 1024 * 1024 + 8192)
        ->and($marker->refresh()->landscape_file_path)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('when fetching finally gives up, the marker says so', function () {
    $marker = readyClip();

    (new FetchClipFile($marker->id))->failed(new RuntimeException('CDN down'));

    expect($marker->refresh()->fetch_error)->toContain('CDN down');
});

test('a clip that is not ready is not fetched', function () {
    Http::fake();
    $marker = StreamMarker::factory()->create(['status' => StreamMarkerStatus::ClipProcessing, 'clip_id' => 'ClipSlug']);

    fetchClip($marker);

    Http::assertNothingSent();
});

// --- playing the files -----------------------------------------------------------

test('moderators can play a fetched file; viewers and guests cannot, and the disk path never appears', function () {
    $marker = readyClip(['landscape_file_path' => 'clips/1/ClipSlug-landscape.mp4', 'fetched_at' => now(), 'file_bytes' => 9]);
    Storage::disk('local')->put('clips/1/ClipSlug-landscape.mp4', 'mp4-bytes');
    $url = route('clips.file', ['marker' => $marker->id, 'variant' => 'landscape']);

    $this->get($url)->assertRedirect();
    $this->actingAs(User::factory()->twitch('4145994')->create())->get($url)->assertForbidden();

    $mod = approvalMod();
    $response = $this->actingAs($mod)->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    expect($response->baseResponse->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')->not->toContain('public');

    $this->actingAs($mod)->get('/clips?show=all')
        ->assertSee($url, false)
        ->assertDontSee('clips/1/ClipSlug-landscape.mp4')
        ->assertDontSee(Storage::disk('local')->path(''));
});

test('a variant with no file, or an unknown variant, is a 404', function () {
    $marker = readyClip();
    $this->actingAs(approvalMod());

    $this->get(route('clips.file', ['marker' => $marker->id, 'variant' => 'portrait']))->assertNotFound();
    $this->get('/clips/'.$marker->id.'/other.mp4')->assertNotFound();
});

// --- review decisions ------------------------------------------------------------

test('approving records the decision and who made it, and publishes nothing', function () {
    Http::fake();
    $mod = approvalMod();
    $marker = readyClip();

    ClipReview::approve($marker, $mod);

    $marker->refresh();
    expect($marker->review_status)->toBe(ClipReviewStatus::Approved)
        ->and($marker->reviewed_by_user_id)->toBe($mod->id)
        ->and($marker->reviewed_at)->not->toBeNull();
    $decision = ClipDecision::sole();
    expect($decision->decision)->toBe(ClipDecisionKind::Approved)
        ->and($decision->user_id)->toBe($mod->id)
        ->and($decision->stream_marker_id)->toBe($marker->id);
    Http::assertNothingSent();
});

test('rejecting keeps an optional reason, cleaned of bidi controls', function () {
    $marker = readyClip();

    ClipReview::reject($marker, approvalMod(), "  off topic\u{202E}  ");

    expect($marker->refresh()->review_status)->toBe(ClipReviewStatus::Rejected)
        ->and(ClipDecision::sole()->note)->toBe('off topic');
});

test('editing stores the title and trim, and sends an approved clip back to review', function () {
    $mod = approvalMod();
    $marker = readyClip(['clip_duration_seconds' => 60]);
    ClipReview::approve($marker, $mod);

    ClipReview::edit($marker, $mod, 'Kale, sung', '12.34', 47);

    $marker->refresh();
    expect($marker->title)->toBe('Kale, sung')
        ->and($marker->trim_start_seconds)->toBe(12.3)
        ->and($marker->trim_end_seconds)->toBe(47.0)
        ->and($marker->review_status)->toBe(ClipReviewStatus::Pending);

    $decisions = ClipDecision::orderBy('id')->get();
    expect($decisions->pluck('decision')->all())->toBe([ClipDecisionKind::Approved, ClipDecisionKind::Edited])
        ->and($decisions->last()->title)->toBe('Kale, sung')
        ->and((float) $decisions->last()->trim_start_seconds)->toBe(12.3);
});

test('a trim must stay inside the clip and keep at least 5 s, and a title is required', function (string $title, mixed $in, mixed $out, string $field) {
    $marker = readyClip(['clip_duration_seconds' => 42.5]);

    try {
        ClipReview::edit($marker, approvalMod(), $title, $in, $out);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect($marker->refresh()->title)->toBeNull()
        ->and(ClipDecision::count())->toBe(0);
})->with([
    'out past the end' => ['t', 0, 42.6, 'trim_end'],
    'out before in' => ['t', 20, 10, 'trim_end'],
    'too short' => ['t', 10, 14.9, 'trim_end'],
    'negative in' => ['t', -1, 20, 'trim_start'],
    'no title' => ['   ', 0, 20, 'title'],
    'title too long' => [str_repeat('a', 101), 0, 20, 'title'],
    'not a number' => ['t', 'abc', 20, 'trim_start'],
]);

test('without a duration from Twitch, the requested length bounds the trim', function () {
    $marker = readyClip(['clip_duration_seconds' => null, 'position_seconds' => 20]);

    expect($marker->clipDuration())->toBe(35.0);
    expect(fn () => ClipReview::edit($marker, approvalMod(), 't', 0, 36))->toThrow(ValidationException::class);
});

test('only a finished clip can be reviewed', function () {
    $marker = StreamMarker::factory()->markerFailed()->create();

    expect(fn () => ClipReview::approve($marker, approvalMod()))->toThrow(ValidationException::class);
    expect(ClipDecision::count())->toBe(0);
});

// --- the approval queue page -----------------------------------------------------

test('the queue shows clips to review by default, and each decision moves them between tabs', function () {
    $mod = approvalMod();
    $this->actingAs($mod);
    $marker = readyClip(['description' => 'review me']);
    StreamMarker::factory()->ready()->create(['description' => 'already approved', 'review_status' => ClipReviewStatus::Approved]);
    StreamMarker::factory()->markerFailed()->create(['description' => 'never clipped']);

    clipsPage()
        ->assertSee('review me')
        ->assertDontSee('already approved')
        ->assertDontSee('never clipped')
        ->call('approve', $marker->id)
        ->assertDontSee('review me')
        ->set('show', 'approved')
        ->assertSee('review me')
        ->assertSee('already approved');

    expect($marker->refresh()->review_status)->toBe(ClipReviewStatus::Approved)
        ->and($marker->reviewed_by_user_id)->toBe($mod->id);
});

test('a moderator edits and rejects through the page', function () {
    $this->actingAs(approvalMod());
    $marker = readyClip(['clip_duration_seconds' => 60]);

    clipsPage()
        ->call('edit', $marker->id)
        ->assertSet('title', 'that drop')
        ->assertSet('trimStart', '0')
        ->assertSet('trimEnd', '60')
        ->set('title', 'Better title')
        ->set('trimStart', '5')
        ->set('trimEnd', '3')
        ->call('save')
        ->assertHasErrors('trim_end')
        ->set('trimEnd', '35.5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', null)
        ->call('startReject', $marker->id)
        ->set('rejectNote', 'not our best')
        ->call('reject');

    $marker->refresh();
    expect($marker->title)->toBe('Better title')
        ->and($marker->trim_end_seconds)->toBe(35.5)
        ->and($marker->review_status)->toBe(ClipReviewStatus::Rejected)
        ->and(ClipDecision::orderBy('id')->pluck('decision')->all())->toBe([ClipDecisionKind::Edited, ClipDecisionKind::Rejected]);
});

test('every page action re-checks the gate, so a viewer cannot approve', function () {
    $marker = readyClip();
    $this->actingAs(User::factory()->twitch('4145994')->create());

    clipsPage()->assertForbidden();

    expect($marker->refresh()->review_status)->toBe(ClipReviewStatus::Pending)
        ->and(ClipDecision::count())->toBe(0);
});

// --- reusing a clip an earlier attempt made --------------------------------------

function clipFromVodFakes(array $clipsSince): void
{
    Http::fake([
        'api.twitch.tv/helix/videos/clips*' => Http::response(['data' => [['id' => 'NewClip', 'edit_url' => 'https://www.twitch.tv/edos/clip/NewClip']]], 202),
        'api.twitch.tv/helix/videos*' => Http::response(['data' => [['id' => '555', 'stream_id' => '40000001']]]),
        'api.twitch.tv/helix/clips*' => Http::response(['data' => $clipsSince]),
    ]);
}

function retriedMarker(array $attributes = []): StreamMarker
{
    $session = StreamSession::factory()->create(['broadcaster_id' => '1000', 'twitch_stream_id' => '40000001']);

    // A previous attempt sent Create Clip From VOD 20 s ago, then died.
    return StreamMarker::factory()->create($attributes + [
        'stream_session_id' => $session->id,
        'position_seconds' => 600,
        'description' => 'that drop',
        'vod_id' => '555',
        'clip_attempted_at' => now()->subSeconds(20),
    ]);
}

function runCreateClip(StreamMarker $marker): CreateClipForMarker
{
    $job = (new CreateClipForMarker($marker->id))->withFakeQueueInteractions();
    $job->handle(app(ClipHelix::class));

    return $job;
}

function earlierClip(array $overrides = []): array
{
    return $overrides + [
        'id' => 'EarlierClip',
        'url' => 'https://clips.twitch.tv/EarlierClip',
        'broadcaster_id' => '1000',
        'creator_id' => '1000',
        'video_id' => '555',
        'title' => 'that drop',
        'vod_offset' => 555,   // starts at 600 + 15 - 60
        'created_at' => now()->subSeconds(18)->toIso8601ZuluString(),
    ];
}

test('a retry reuses the clip the earlier attempt made, instead of creating another', function () {
    clipFromVodFakes([earlierClip()]);
    $marker = retriedMarker();

    runCreateClip($marker)->assertReleased(CreateClipForMarker::POLL_SECONDS);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.twitch.tv/helix/clips?')
        && $r['broadcaster_id'] === '1000'
        && $r['started_at'] === $marker->clip_attempted_at->copy()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    expect($marker->refresh()->clip_id)->toBe('EarlierClip')
        ->and($marker->status)->toBe(StreamMarkerStatus::ClipProcessing);
});

test('a clip whose vod_offset Twitch has not set yet matches on its title', function () {
    clipFromVodFakes([earlierClip(['vod_offset' => null, 'video_id' => ''])]);
    $marker = retriedMarker();

    runCreateClip($marker);

    expect($marker->refresh()->clip_id)->toBe('EarlierClip');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

test('a clip that is not this attempt\'s is not reused, and a new clip is created', function (array $other) {
    clipFromVodFakes([earlierClip($other)]);
    StreamMarker::factory()->create(['clip_id' => 'TakenClip']);
    $marker = retriedMarker();

    runCreateClip($marker);

    expect($marker->refresh()->clip_id)->toBe('NewClip');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST');
})->with([
    'made by a viewer' => [['creator_id' => '4145994']],
    'another VOD' => [['video_id' => '999']],
    'another moment' => [['vod_offset' => 300]],
    'no offset and another title' => [['vod_offset' => null, 'title' => 'someone else']],
    'owned by another marker' => [['id' => 'TakenClip']],
]);

test('a first attempt does not look for an earlier clip, and records when it was sent', function () {
    $this->freezeTime();
    clipFromVodFakes([earlierClip()]);
    $marker = retriedMarker(['clip_attempted_at' => null]);

    runCreateClip($marker);

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.twitch.tv/helix/clips?'));
    expect($marker->refresh()->clip_id)->toBe('NewClip')
        ->and($marker->clip_attempted_at->getTimestamp())->toBe(now()->getTimestamp());
});

test('a 429 on the lookup releases the job without creating a clip', function () {
    $this->freezeTime();
    Http::fake(['api.twitch.tv/helix/clips*' => Http::response([], 429, ['Ratelimit-Reset' => (string) (now()->getTimestamp() + 9)])]);
    $marker = retriedMarker();

    runCreateClip($marker)->assertReleased(9);

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    expect($marker->refresh()->clip_id)->toBeNull();
});

test('Get Clips duration is stored when the clip is ready, and bounds the trim', function () {
    Queue::fake([PostChatReply::class, FetchClipFile::class]);
    Http::fake([
        'api.twitch.tv/helix/clips/downloads*' => Http::response(['data' => [['clip_id' => 'ClipSlug', 'landscape_download_url' => CDN.'/l.mp4', 'portrait_download_url' => null]]]),
        'api.twitch.tv/helix/clips*' => Http::response(['data' => [['id' => 'ClipSlug', 'duration' => 59.9]]]),
    ]);
    $marker = retriedMarker(['clip_id' => 'ClipSlug', 'status' => StreamMarkerStatus::ClipProcessing, 'clip_requested_at' => now()]);

    runCreateClip($marker);

    expect($marker->refresh()->clip_duration_seconds)->toBe(59.9)
        ->and($marker->clipDuration())->toBe(59.9);
    Queue::assertPushed(FetchClipFile::class);
});
