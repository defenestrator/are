<?php

use App\Jobs\PollYouTubeLiveChat;
use App\Models\YouTubeLiveChat;
use App\YouTube\Quota;
use App\YouTube\ShowWindows;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

const AUTO_MAIN = 'UCedosMainChannel00000000';
const AUTO_SECOND = 'UCedosSecondChannel000000';

beforeEach(function () {
    config([
        'services.youtube.api_key' => 'yt-test-key',
        'services.youtube.channel_ids' => [AUTO_MAIN, AUTO_SECOND],
        'services.youtube.poll_floor_ms' => 3000,
        'services.youtube.quota.daily_units' => 10000,
        'services.youtube.quota.daily_search_calls' => 100,
        'services.youtube.quota.alert_ratio' => 0.8,
        'services.youtube.auto.search_every_minutes' => 15,
        'services.youtube.auto.windows' => [],
        'services.youtube.auto.timezone' => 'UTC',
    ]);
    $this->travelTo(Carbon::parse('2026-10-04T19:00:00Z'));
    Queue::fake();
});

/**
 * search.list answers shaped like the documented searchListResponse: the main
 * channel is live, the second is not.
 *
 * @see https://developers.google.com/youtube/v3/docs/search/list
 */
function fakeYouTubeSearch(array $live = [AUTO_MAIN => 'edosLive001']): void
{
    Http::fake([
        'www.googleapis.com/youtube/v3/search*' => function (Request $request) use ($live) {
            $videoId = $live[$request['channelId']] ?? null;

            return Http::response([
                'kind' => 'youtube#searchListResponse',
                'regionCode' => 'US',
                'pageInfo' => ['totalResults' => $videoId ? 1 : 0, 'resultsPerPage' => 1],
                'items' => $videoId ? [['kind' => 'youtube#searchResult', 'id' => ['kind' => 'youtube#video', 'videoId' => $videoId]]] : [],
            ]);
        },
        'www.googleapis.com/youtube/v3/videos*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/youtube/videos.list.json')), true)),
    ]);
}

function searchesSent(): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/search'))->count();
}

test('--auto finds the live video of each configured channel and starts reading its chat', function () {
    fakeYouTubeSearch();

    $this->artisan('youtube:chat', ['--auto' => true])
        ->expectsOutputToContain(AUTO_SECOND.': not live.')
        ->expectsOutputToContain('edosLive001: reading chat')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/search')
        && $r['channelId'] === AUTO_MAIN
        && $r['eventType'] === 'live'
        && $r['type'] === 'video'
        && $r->hasHeader('X-Goog-Api-Key', 'yt-test-key')
        && ! str_contains($r->url(), 'yt-test-key'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/videos') && $r['id'] === 'edosLive001');

    expect(searchesSent())->toBe(2)
        ->and(Quota::used(Quota::SEARCH))->toBe(2)
        ->and(Quota::used(Quota::UNITS))->toBe(1) // the one videos.list
        ->and(YouTubeLiveChat::polling()->pluck('video_id')->all())->toBe(['edosLive001']);
    Queue::assertPushed(PollYouTubeLiveChat::class, 1);
});

test('each channel is searched at most once every 15 minutes', function () {
    fakeYouTubeSearch([]);

    $this->artisan('youtube:chat', ['--auto' => true])->assertSuccessful();
    $this->travel(14)->minutes();
    $this->artisan('youtube:chat', ['--auto' => true])
        ->expectsOutputToContain('searched less than 15 minutes ago')
        ->assertSuccessful();
    expect(searchesSent())->toBe(2);

    $this->travel(1)->minutes();
    $this->artisan('youtube:chat', ['--auto' => true])->assertSuccessful();
    expect(searchesSent())->toBe(4)
        ->and(Quota::used(Quota::SEARCH))->toBe(4);
});

test('a channel whose chat is already being read is not searched', function () {
    fakeYouTubeSearch();
    YouTubeLiveChat::factory()->create(['channel_id' => AUTO_MAIN]);

    $this->artisan('youtube:chat', ['--auto' => true])->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/search') && $r['channelId'] === AUTO_MAIN);
    expect(searchesSent())->toBe(1);
});

test('searching stops when the day\'s search bucket is spent', function () {
    fakeYouTubeSearch();
    DB::table('youtube_quota_usage')->insert(['day' => Quota::day(), 'bucket' => Quota::SEARCH, 'used' => 100, 'calls' => 100, 'failed_calls' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('youtube:chat', ['--auto' => true])
        ->expectsOutputToContain('search.list quota is spent')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('a failed search is charged, and waits out the interval before trying again', function () {
    Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);

    $this->artisan('youtube:chat', ['--auto' => true])
        ->expectsOutputToContain('search.list failed (403): quotaExceeded')
        ->assertSuccessful();
    $this->artisan('youtube:chat', ['--auto' => true])->assertSuccessful();

    expect(searchesSent())->toBe(2)
        ->and(Quota::used(Quota::SEARCH))->toBe(2)
        ->and(Quota::failedCalls(Quota::SEARCH))->toBe(2);
});

test('--auto needs an API key and configured channels', function () {
    Http::fake();

    config(['services.youtube.channel_ids' => []]);
    $this->artisan('youtube:chat', ['--auto' => true])->assertFailed();

    config(['services.youtube.channel_ids' => [AUTO_MAIN], 'services.youtube.api_key' => null]);
    $this->artisan('youtube:chat', ['--auto' => true])->assertFailed();

    Http::assertNothingSent();
});

// --- Show windows -------------------------------------------------------------------

test('show windows open and close at the configured times, in the configured zone', function (string $at, bool $open) {
    config([
        'services.youtube.auto.timezone' => 'America/Denver',
        'services.youtube.auto.windows' => ['sun 17:00-21:00', 'fri 22:00-01:00'],
    ]);

    expect(ShowWindows::isOpen(Carbon::parse($at)))->toBe($open);
})->with([
    // 4 October 2026 is a Sunday; Denver is UTC-6 (MDT).
    'Sunday 16:59 MDT' => ['2026-10-04T22:59:00Z', false],
    'Sunday 17:00 MDT' => ['2026-10-04T23:00:00Z', true],
    'Sunday 20:59 MDT' => ['2026-10-05T02:59:00Z', true],
    'Sunday 21:00 MDT' => ['2026-10-05T03:00:00Z', false],
    'Friday 23:30 MDT' => ['2026-10-10T05:30:00Z', true],
    'past midnight into Saturday 00:30 MDT' => ['2026-10-10T06:30:00Z', true],
    'Saturday 01:00 MDT' => ['2026-10-10T07:00:00Z', false],
    'Wednesday 18:00 MDT' => ['2026-10-07T00:00:00Z', false],
]);

test('with no windows configured, discovery never runs on its own', function () {
    expect(ShowWindows::isOpen())->toBeFalse();
});

test('a window that does not parse is ignored and logged', function () {
    Log::spy();
    config(['services.youtube.auto.windows' => ['someday 17:00-21:00', 'sun 25:00-26:00', 'sun 19:00-20:00']]);

    expect(ShowWindows::parse())->toBe([[0, 19 * 60, 20 * 60]]);
    Log::shouldHaveReceived('warning')->twice();
});

test('the scheduler runs --auto every minute, but only inside a show window', function () {
    config(['services.youtube.auto.windows' => ['sun 18:00-20:00']]);
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'youtube:chat --auto'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');

    $this->travelTo(Carbon::parse('2026-10-04T19:00:00Z')); // Sunday 19:00 UTC
    expect($event->filtersPass(app()))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-10-04T21:00:00Z'));
    expect($event->filtersPass(app()))->toBeFalse();
});
