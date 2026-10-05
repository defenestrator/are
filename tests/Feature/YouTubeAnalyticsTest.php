<?php

use App\Analytics\AttributionReport;
use App\Analytics\DateRange;
use App\Analytics\YouTubeChannelReport;
use App\Jobs\FetchYouTubeAnalytics;
use App\Jobs\PostWeeklyAttributionSummary;
use App\Models\User;
use App\Models\YouTubeChannelToken;
use App\Notifications\WeeklyAttributionSummary;
use App\YouTube\AnalyticsTokens;
use App\YouTube\StoredAnalyticsTokens;
use App\YouTube\YouTubeApi;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const YT_A = 'UCaaaaaaaaaaaaaaaaaaaaaa';
const YT_B = 'UCbbbbbbbbbbbbbbbbbbbbbb';

/** Tokens for the given channels; a channel mapped to null cannot get a token. */
function fakeAnalyticsTokens(array $channels = [YT_A => 'EDOS', YT_B => 'EDOS Clips'], array $failing = []): void
{
    app()->instance(AnalyticsTokens::class, new class($channels, $failing) implements AnalyticsTokens
    {
        public function __construct(private array $channels, private array $failing) {}

        public function channels(): array
        {
            return $this->channels;
        }

        public function accessTokenFor(string $channelId): string
        {
            if (in_array($channelId, $this->failing, true)) {
                throw new RuntimeException("YouTube channel {$channelId} is not connected.");
            }

            return 'token-'.$channelId;
        }
    });
}

/** A reports.query response: rows of [day, creatorContentType, subscribedStatus, views, engagedViews, minutes, avgDuration]. */
function analyticsResponse(array $rows): array
{
    return [
        'kind' => 'youtubeAnalytics#resultTable',
        'columnHeaders' => [
            ['name' => 'day', 'columnType' => 'DIMENSION', 'dataType' => 'STRING'],
            ['name' => 'creatorContentType', 'columnType' => 'DIMENSION', 'dataType' => 'STRING'],
            ['name' => 'subscribedStatus', 'columnType' => 'DIMENSION', 'dataType' => 'STRING'],
            ['name' => 'views', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
            ['name' => 'engagedViews', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
            ['name' => 'estimatedMinutesWatched', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
            ['name' => 'averageViewDuration', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
        ],
        'rows' => $rows,
    ];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-05 06:00:00'));
});

test('it queries each connected channel with its own token, the right report and a trailing window', function () {
    fakeAnalyticsTokens();
    Http::fake(['youtubeanalytics.googleapis.com/*' => Http::response(analyticsResponse([]))]);

    (new FetchYouTubeAnalytics)->handle(app(AnalyticsTokens::class));

    Http::assertSentCount(2);
    foreach ([YT_A, YT_B] as $channel) {
        Http::assertSent(function (Request $r) use ($channel) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_starts_with($r->url(), 'https://youtubeanalytics.googleapis.com/v2/reports?')
                && $r->hasHeader('Authorization', 'Bearer token-'.$channel)
                && $q === [
                    'ids' => 'channel==MINE',
                    'startDate' => '2026-09-21',
                    'endDate' => '2026-10-05',
                    'dimensions' => 'day,creatorContentType,subscribedStatus',
                    'metrics' => 'views,engagedViews,estimatedMinutesWatched,averageViewDuration',
                    'sort' => 'day',
                ];
        });
    }
});

test('rows are stored per channel, day, content type and subscribed status, with the last reported day', function () {
    fakeAnalyticsTokens([YT_A => 'EDOS']);
    Http::fake(['youtubeanalytics.googleapis.com/*' => Http::response(analyticsResponse([
        ['2026-10-01', 'LIVE_STREAM', 'SUBSCRIBED', 120, 110, 900, 450],
        ['2026-10-01', 'LIVE_STREAM', 'UNSUBSCRIBED', 200, 160, 600, 180],
        ['2026-10-03', 'VIDEO_ON_DEMAND', 'UNSUBSCRIBED', 80, 70, 160, 120],
    ]))]);

    (new FetchYouTubeAnalytics)->handle(app(AnalyticsTokens::class));

    expect(DB::table('youtube_channel_days')->count())->toBe(3)
        ->and((array) DB::table('youtube_channel_days')->where('subscribed_status', 'SUBSCRIBED')->first(['channel_id', 'content_type', 'views', 'engaged_views', 'estimated_minutes_watched', 'average_view_duration']))
        ->toBe(['channel_id' => YT_A, 'content_type' => 'LIVE_STREAM', 'views' => 120, 'engaged_views' => 110, 'estimated_minutes_watched' => 900, 'average_view_duration' => 450])
        ->and(substr((string) DB::table('youtube_analytics_syncs')->where('channel_id', YT_A)->value('reported_through'), 0, 10))->toBe('2026-10-03');
});

test('a re-fetch updates revised days instead of duplicating them, and never moves reported_through back', function () {
    fakeAnalyticsTokens([YT_A => 'EDOS']);
    Http::fakeSequence('youtubeanalytics.googleapis.com/*')
        ->push(analyticsResponse([['2026-10-03', 'LIVE_STREAM', 'UNSUBSCRIBED', 50, 40, 100, 120]]))
        ->push(analyticsResponse([['2026-10-02', 'LIVE_STREAM', 'UNSUBSCRIBED', 9, 9, 9, 60], ['2026-10-03', 'LIVE_STREAM', 'UNSUBSCRIBED', 75, 60, 150, 120]]))
        ->push(analyticsResponse([]));

    $job = new FetchYouTubeAnalytics;
    $job->handle(app(AnalyticsTokens::class));
    $job->handle(app(AnalyticsTokens::class));

    expect(DB::table('youtube_channel_days')->count())->toBe(2)
        ->and(DB::table('youtube_channel_days')->where('day', '2026-10-03')->value('views'))->toBe(75);

    $job->handle(app(AnalyticsTokens::class)); // an empty answer keeps the last known day

    expect(substr((string) DB::table('youtube_analytics_syncs')->value('reported_through'), 0, 10))->toBe('2026-10-03');
});

test('a channel that fails is logged without its token, the others are still fetched, and the job fails for a retry', function () {
    fakeAnalyticsTokens(failing: [YT_A]);
    Log::spy();
    Http::fake(['youtubeanalytics.googleapis.com/*' => Http::response(analyticsResponse([['2026-10-03', 'LIVE_STREAM', 'SUBSCRIBED', 5, 5, 5, 60]]))]);

    expect(fn () => (new FetchYouTubeAnalytics)->handle(app(AnalyticsTokens::class)))->toThrow(RuntimeException::class);

    expect(DB::table('youtube_channel_days')->pluck('channel_id')->all())->toBe([YT_B]);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c['channel_id'] === YT_A
        && ! str_contains(json_encode($c), 'token-'));
});

test('a refused query is logged with Google\'s reason only', function () {
    fakeAnalyticsTokens([YT_A => 'EDOS']);
    Log::spy();
    Http::fake(['youtubeanalytics.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'errors' => [['reason' => 'insufficientPermissions']]]], 403)]);

    expect(fn () => (new FetchYouTubeAnalytics)->handle(app(AnalyticsTokens::class)))->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c['error'] === 'HTTP 403 insufficientPermissions');
});

test('no connected channel means no request', function () {
    fakeAnalyticsTokens([]);
    Http::fake();

    (new FetchYouTubeAnalytics)->handle(app(AnalyticsTokens::class));

    Http::assertNothingSent();
});

// --- the report ----------------------------------------------------------------

function seedDays(string $channel, array $rows, ?string $reportedThrough): void
{
    foreach ($rows as [$day, $type, $status, $views, $engaged, $minutes]) {
        DB::table('youtube_channel_days')->insert([
            'channel_id' => $channel, 'day' => $day, 'content_type' => $type, 'subscribed_status' => $status,
            'views' => $views, 'engaged_views' => $engaged, 'estimated_minutes_watched' => $minutes, 'average_view_duration' => 0,
        ]);
    }
    DB::table('youtube_analytics_syncs')->insert(['channel_id' => $channel, 'reported_through' => $reportedThrough, 'synced_at' => now()]);
}

test('views, engaged views, average view duration, subscriber views and live views per channel for a range', function () {
    seedDays(YT_A, [
        ['2026-09-28', 'LIVE_STREAM', 'SUBSCRIBED', 100, 90, 500],
        ['2026-09-30', 'LIVE_STREAM', 'UNSUBSCRIBED', 300, 200, 400],
        ['2026-10-04', 'VIDEO_ON_DEMAND', 'UNSUBSCRIBED', 100, 60, 100],
        ['2026-09-27', 'LIVE_STREAM', 'SUBSCRIBED', 999, 999, 999], // before the range
    ], '2026-10-04');

    [$report] = YouTubeChannelReport::for(DateRange::preset('last_week'), [YT_A => 'EDOS']);

    expect($report->title)->toBe('EDOS')
        ->and($report->views)->toBe(500)
        ->and($report->engagedViews)->toBe(350)
        ->and($report->averageViewDuration())->toBe(120) // 1000 minutes × 60 ÷ 500 views
        ->and($report->averageViewDurationLabel())->toBe('2:00')
        ->and($report->subscriberViews)->toBe(100)
        ->and($report->subscriberShareLabel())->toBe('20%')
        ->and($report->liveStreamViews)->toBe(400)
        ->and($report->isPartial())->toBeFalse()
        ->and($report->coverageLabel())->toBe('Mon 28 Sep to Sun 4 Oct');
});

test('a range YouTube has not finished reporting is marked partial, through its last reported day', function () {
    seedDays(YT_A, [['2026-10-01', 'LIVE_STREAM', 'SUBSCRIBED', 10, 10, 10]], '2026-10-02');

    [$report] = YouTubeChannelReport::for(DateRange::preset('last_week'), [YT_A => 'EDOS']);

    expect($report->isPartial())->toBeTrue()
        ->and($report->coversThrough()->toDateString())->toBe('2026-10-02')
        ->and($report->coverageLabel())->toBe('Mon 28 Sep to Fri 2 Oct (YouTube has reported through Fri 2 Oct)');
});

test('a channel never fetched, or reported only up to before the range, has no data for it', function () {
    seedDays(YT_B, [], '2026-09-20');

    $reports = YouTubeChannelReport::for(DateRange::preset('last_week'), [YT_A => 'EDOS', YT_B => 'EDOS Clips']);

    expect($reports[0]->coverageLabel())->toBe('no YouTube data for this range yet')
        ->and($reports[0]->isPartial())->toBeTrue()
        ->and($reports[0]->averageViewDurationLabel())->toBe('—')
        ->and($reports[1]->coverageLabel())->toBe('no YouTube data for this range yet');
});

// --- where it shows ------------------------------------------------------------

function seedTwoChannels(): void
{
    seedDays(YT_A, [
        ['2026-09-28', 'LIVE_STREAM', 'SUBSCRIBED', 100, 90, 500],
        ['2026-09-30', 'LIVE_STREAM', 'UNSUBSCRIBED', 300, 200, 400],
        ['2026-10-04', 'VIDEO_ON_DEMAND', 'UNSUBSCRIBED', 100, 60, 100],
    ], '2026-10-04');
    seedDays(YT_B, [['2026-09-29', 'SHORTS', 'UNSUBSCRIBED', 40, 30, 20]], '2026-10-02');
}

test('/admin/attribution shows each connected channel, and marks a range YouTube has not finished', function () {
    fakeAnalyticsTokens();
    seedTwoChannels();

    $this->actingAs(User::factory()->twitch('1000')->create())
        ->get('/admin/attribution?preset=last_week')
        ->assertOk()
        ->assertSee('YouTube channels')
        ->assertSee('it is not returning viewers')
        ->assertSeeInOrder(['EDOS', '500', '350', '2:00', '100 (20%)', '400', 'Mon 28 Sep to Sun 4 Oct'])
        ->assertSeeInOrder(['EDOS Clips', '40', '30', '0:30', '0 (0%)', '0', 'Mon 28 Sep to Fri 2 Oct (YouTube has reported through Fri 2 Oct)']);
});

test('with no channel connected, the page says how to connect one', function () {
    $this->actingAs(User::factory()->twitch('1000')->create())
        ->get('/admin/attribution')
        ->assertOk()
        ->assertSee('No YouTube channel is connected for analytics.');
});

test('the weekly summary has a YouTube section, with partial weeks called out', function () {
    fakeAnalyticsTokens();
    seedTwoChannels();
    $range = DateRange::preset('last_week');

    $text = (new WeeklyAttributionSummary(
        AttributionReport::for($range),
        [],
        YouTubeChannelReport::for($range, app(AnalyticsTokens::class)->channels()),
    ))->text();

    expect($text)->toContain(implode("\n", [
        '',
        'YouTube (engaged views count plays past the first frame; views from subscribers is not returning viewers):',
        '• EDOS: 500 views, 350 engaged, avg 2:00 watched, 100 views from subscribers (20%), 400 live-stream views',
        '• EDOS Clips: 40 views, 30 engaged, avg 0:30 watched, 0 views from subscribers (0%), 0 live-stream views; partial week, YouTube has reported through Fri 2 Oct',
        '',
        'Full report: ',
    ]));
});

test('a channel with no data yet says so in the summary', function () {
    $range = DateRange::preset('last_week');

    $text = (new WeeklyAttributionSummary(
        AttributionReport::for($range),
        [],
        YouTubeChannelReport::for($range, [YT_A => 'EDOS']),
    ))->text();

    expect($text)->toContain('• EDOS: no YouTube data for this week yet');
});

test('the posted weekly summary includes connected channels, and none without a connection', function () {
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
    config(['are.weekly_summary.webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    fakeAnalyticsTokens([YT_A => 'EDOS']);
    seedTwoChannels();

    (new PostWeeklyAttributionSummary)->handle();

    expect(Http::recorded()[0][0]['text'])->toContain('• EDOS: 500 views')->not->toContain('EDOS Clips');
});

test('the fetch runs daily at 06:00 app time, before the Monday 09:00 summary, only with a channel connected', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->description, FetchYouTubeAnalytics::class));

    expect($event->expression)->toBe('0 6 * * *')
        ->and($event->timezone)->toBe(config('app.timezone'))
        ->and($event->filtersPass($this->app))->toBeFalse();

    fakeAnalyticsTokens([YT_A => 'EDOS']);

    expect($event->filtersPass($this->app))->toBeTrue();
});

// --- the stored tokens (#126) ----------------------------------------------------

function storeYouTubeToken(string $channelId, ?string $title, array $scopes, string $accessToken = 'stored-access'): YouTubeChannelToken
{
    return YouTubeChannelToken::create([
        'channel_id' => $channelId,
        'channel_title' => $title,
        'access_token' => $accessToken,
        'refresh_token' => 'stored-refresh',
        'expires_at' => now()->addHour(),
        'scopes' => $scopes,
    ]);
}

test('the app reads analytics tokens from the broadcaster Google connection', function () {
    expect(app(AnalyticsTokens::class))->toBeInstanceOf(StoredAnalyticsTokens::class);
});

test('only channels whose stored token has both analytics scopes are listed, by title', function () {
    storeYouTubeToken(YT_B, 'Zed Channel', [YouTubeApi::POST_SCOPE, ...YouTubeApi::ANALYTICS_SCOPES]);
    storeYouTubeToken(YT_A, 'EDOS', [...YouTubeApi::ANALYTICS_SCOPES]);
    storeYouTubeToken('UCreplies-only', 'Replies only', [YouTubeApi::POST_SCOPE]);
    storeYouTubeToken('UChalf', 'Half', [YouTubeApi::POST_SCOPE, 'https://www.googleapis.com/auth/yt-analytics.readonly']);
    storeYouTubeToken('UCuntitled', null, [...YouTubeApi::ANALYTICS_SCOPES]);

    expect((new StoredAnalyticsTokens)->channels())->toBe([
        YT_A => 'EDOS',
        'UCuntitled' => 'UCuntitled',
        YT_B => 'Zed Channel',
    ]);
});

test('the fetch uses the stored, decrypted token through YouTubeApi::accessTokenFor', function () {
    storeYouTubeToken(YT_A, 'EDOS', [...YouTubeApi::ANALYTICS_SCOPES], accessToken: 'real-stored-access');
    Http::fake(['youtubeanalytics.googleapis.com/*' => Http::response(analyticsResponse([]))]);

    (new FetchYouTubeAnalytics)->handle(app(AnalyticsTokens::class));

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer real-stored-access'));
});

test('the empty-state links to the connect route', function () {
    $this->actingAs(User::factory()->twitch('1000')->create())
        ->get('/admin/attribution')
        ->assertSee(route('youtube.broadcaster.connect'), false);
});
