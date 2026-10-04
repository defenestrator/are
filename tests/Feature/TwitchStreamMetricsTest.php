<?php

use App\Analytics\AttributionReport;
use App\Analytics\DateRange;
use App\Analytics\StreamMetrics;
use App\Jobs\EventSub\HandleChatMessage;
use App\Jobs\EventSub\HandleStreamOffline;
use App\Jobs\EventSub\HandleStreamOnline;
use App\Jobs\PostWeeklyAttributionSummary;
use App\Jobs\SampleTwitchViewers;
use App\Models\Lead;
use App\Models\ShortLink;
use App\Models\ShortLinkClick;
use App\Models\StreamChatter;
use App\Models\StreamSession;
use App\Models\StreamViewerSample;
use App\Models\User;
use App\Notifications\WeeklyAttributionSummary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 20:00:00'));
});

/** Helix Get Streams answering with these live streams. */
function fakeGetStreams(array $streams): void
{
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
        'api.twitch.tv/helix/streams*' => Http::response(['data' => array_map(fn (array $s) => $s + ['type' => 'live'], $streams), 'pagination' => []]),
    ]);
}

function liveSession(string $streamId, string $broadcasterId = '1000', string $startedAt = '2026-10-01 19:00:00'): StreamSession
{
    return StreamSession::factory()->create([
        'broadcaster_id' => $broadcasterId,
        'twitch_stream_id' => $streamId,
        'started_at' => $startedAt,
        'ended_at' => null,
    ]);
}

function samples(StreamSession $session, int ...$counts): void
{
    foreach ($counts as $i => $count) {
        StreamViewerSample::create([
            'stream_session_id' => $session->id,
            'sampled_at' => $session->started_at->copy()->addMinutes(3 * ($i + 1)),
            'viewer_count' => $count,
        ]);
    }
}

function chat(string $chatterId, array $overrides = []): void
{
    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), $overrides + [
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'chatter_user_id' => $chatterId,
        'chatter_user_login' => 'viewer'.$chatterId,
        'chatter_user_name' => 'viewer'.$chatterId,
        'message_id' => (string) Str::uuid(),
        'message' => ['text' => 'hello from '.$chatterId, 'fragments' => []],
        'message_type' => 'text',
        'badges' => [],
    ]))->handle();
}

// --- sampling start and stop --------------------------------------------------

test('stream.online queues the first viewer sample, 30 seconds later', function () {
    Queue::fake();

    (new HandleStreamOnline('msg-online', now()->toIso8601ZuluString(), [
        'id' => '111', 'broadcaster_user_id' => '1000', 'type' => 'live', 'started_at' => now()->toIso8601ZuluString(),
    ]))->handle();

    Queue::assertPushed(SampleTwitchViewers::class, fn (SampleTwitchViewers $job) => $job->delay !== null
        && Carbon::parse($job->delay)->equalTo(now()->addSeconds(30)));
});

test('one Get Streams call, with the app token, samples every open session', function () {
    $a = liveSession('111', '1000');
    $b = liveSession('222', '2000');
    StreamSession::factory()->ended()->create(['broadcaster_id' => '3000', 'twitch_stream_id' => '333']);
    fakeGetStreams([
        ['id' => '111', 'user_id' => '1000', 'viewer_count' => 42],
        ['id' => '222', 'user_id' => '2000', 'viewer_count' => 7],
    ]);

    (new SampleTwitchViewers)->handle();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.twitch.tv/helix/streams?')
        && str_contains($r->url(), 'user_id=1000&user_id=2000')
        && ! str_contains($r->url(), '3000')
        && $r->hasHeader('Authorization', 'Bearer app-token')
        && $r->hasHeader('Client-ID', 'client-id'));
    Http::assertSentCount(2); // the token, then one Get Streams

    expect(StreamViewerSample::where('stream_session_id', $a->id)->sole())
        ->viewer_count->toBe(42)
        ->sampled_at->toDateTimeString()->toBe('2026-10-01 20:00:00')
        ->and(StreamViewerSample::where('stream_session_id', $b->id)->sole()->viewer_count)->toBe(7);
});

test('a stream Helix reports under a different id is not credited to the open session', function () {
    liveSession('111');
    fakeGetStreams([['id' => '999', 'user_id' => '1000', 'viewer_count' => 50]]);

    (new SampleTwitchViewers)->handle();

    expect(StreamViewerSample::count())->toBe(0);
});

test('a duplicate sample in the same minute is recorded once; the next tick adds one', function () {
    $session = liveSession('111');
    fakeGetStreams([['id' => '111', 'user_id' => '1000', 'viewer_count' => 42]]);

    (new SampleTwitchViewers)->handle();
    (new SampleTwitchViewers)->handle();
    $this->travel(3)->minutes();
    (new SampleTwitchViewers)->handle();

    expect($session->viewerSamples()->count())->toBe(2);
});

test('stream.offline stops sampling: no open session, no call', function () {
    Queue::fake([SampleTwitchViewers::class]);
    $session = liveSession('111');
    fakeGetStreams([['id' => '111', 'user_id' => '1000', 'viewer_count' => 42]]);

    (new HandleStreamOffline('msg-offline', now()->toIso8601ZuluString(), ['broadcaster_user_id' => '1000']))->handle();
    (new SampleTwitchViewers)->handle();

    expect($session->fresh()->ended_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('the scheduler samples every three minutes, only while a stream is live', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->description, SampleTwitchViewers::class));

    expect($event->expression)->toBe('*/3 * * * *')
        ->and($event->filtersPass($this->app))->toBeFalse();

    liveSession('111');

    expect($event->filtersPass($this->app))->toBeTrue();
});

test('a failed Get Streams call is logged without the token, stores nothing and does not throw', function () {
    liveSession('111');
    Log::spy();
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
        'api.twitch.tv/helix/streams*' => Http::response(['message' => 'oops'], 503),
    ]);

    (new SampleTwitchViewers)->handle();

    expect(StreamViewerSample::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c === ['status' => 503]
        && ! str_contains($m.json_encode($c), 'app-token'));
});

test('no app token means no sample, logged, not thrown', function () {
    liveSession('111');
    Log::spy();
    Http::fake(['id.twitch.tv/oauth2/token' => Http::response(['message' => 'invalid client'], 400)]);

    (new SampleTwitchViewers)->handle();

    expect(StreamViewerSample::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});

// --- averages and chatters ----------------------------------------------------

test('average and peak viewers, and participation, per session', function () {
    $session = liveSession('111');
    samples($session, 10, 20, 33);
    foreach (range(1, 7) as $i) {
        $session->recordChatter((string) $i);
    }

    $metrics = StreamMetrics::forRange(DateRange::preset('this_week'))[0];

    expect($metrics->averageViewers)->toBe(21.0)
        ->and($metrics->peakViewers)->toBe(33)
        ->and($metrics->samples)->toBe(3)
        ->and($metrics->uniqueChatters)->toBe(7)
        ->and($metrics->participation())->toBe(7 / 21)
        ->and($metrics->participationLabel())->toBe('≈ 33%')
        ->and($metrics->averageLabel())->toBe('21')
        ->and($metrics->stream())->toBe('2026-10-01-stream-111');
});

test('without samples there is no average and no participation, rather than zero', function () {
    $session = liveSession('111');
    $session->recordChatter('1');

    $metrics = StreamMetrics::forRange(DateRange::preset('this_week'))[0];

    expect($metrics->averageViewers)->toBeNull()
        ->and($metrics->participation())->toBeNull()
        ->and($metrics->participationLabel())->toBe('—')
        ->and($metrics->peakLabel())->toBe('—');
});

test('only sessions that started in the range are reported', function () {
    liveSession('old', startedAt: '2026-09-20 19:00:00');
    liveSession('111');

    expect(array_map(fn (StreamMetrics $m) => $m->session->twitch_stream_id, StreamMetrics::forRange(DateRange::preset('this_week'))))
        ->toBe(['111']);
});

test('chat messages count distinct chatters per session, by hash only', function () {
    $session = liveSession('111');

    chat('501');
    chat('501');
    chat('501');
    chat('502');
    chat('1000'); // the broadcaster, and ARE's own replies
    chat('503', ['source_broadcaster_user_id' => '777']); // relayed from another channel in Shared Chat
    chat('504', ['broadcaster_user_id' => '2000']); // a channel with no live session

    expect($session->chatters()->count())->toBe(2)
        ->and(StreamChatter::count())->toBe(2)
        ->and(StreamChatter::pluck('chatter_hash')->all())->toEqualCanonicalizing([
            StreamSession::chatterHash('501'),
            StreamSession::chatterHash('502'),
        ])
        ->and(StreamSession::chatterHash('501'))->not->toBe(hash('sha256', '501'))->toHaveLength(64)
        ->and(Schema::getColumnListing('stream_chatters'))->toEqualCanonicalizing(['id', 'stream_session_id', 'chatter_hash', 'first_seen_at']);
});

test('a chatter is counted again in a later stream', function () {
    $first = liveSession('111');
    chat('501');
    $first->update(['ended_at' => now()]);

    $second = liveSession('222', startedAt: '2026-10-01 20:30:00');
    $this->travel(40)->minutes();
    chat('501');

    expect($first->chatters()->count())->toBe(1)->and($second->chatters()->count())->toBe(1);
});

// --- where it shows -----------------------------------------------------------

function streamWithAudience(): StreamSession
{
    $session = liveSession('111');
    samples($session, 30, 50);
    foreach (range(1, 12) as $i) {
        $session->recordChatter((string) $i);
    }

    $twitch = ShortLink::factory()->create(['utm_source' => 'twitch', 'utm_campaign' => $session->utmCampaign()]);
    $youtube = ShortLink::factory()->create(['utm_source' => 'youtube', 'utm_campaign' => $session->utmCampaign()]);
    ShortLinkClick::factory()->count(8)->for($twitch)->create(['clicked_at' => '2026-10-01 19:30:00']);
    ShortLinkClick::factory()->count(2)->for($youtube)->create(['clicked_at' => '2026-10-01 19:30:00']);
    Lead::factory()->create(['utm_source' => 'twitch', 'utm_campaign' => $session->utmCampaign(), 'created_at' => '2026-10-01 19:45:00']);

    return $session;
}

test('/admin/attribution shows each stream\'s audience next to its clicks and enquiries', function () {
    streamWithAudience();

    $this->actingAs(User::factory()->twitch('1000')->create())
        ->get('/admin/attribution')
        ->assertOk()
        ->assertSee('Twitch streams')
        ->assertSee('Participation (approx.)')
        ->assertSee('an approximation that reads high')
        ->assertSeeInOrder(['2026-10-01-stream-111', 'Thu 1 Oct 19:00 (live)', '40', '50', '12', '≈ 30%', '10', '1']);
});

test('the attribution page says so when no stream started in the range', function () {
    $this->actingAs(User::factory()->twitch('1000')->create())
        ->get('/admin/attribution')
        ->assertOk()
        ->assertSee('No Twitch streams started in this range.');
});

test('the weekly summary carries a Twitch streams section', function () {
    $session = streamWithAudience();
    $range = DateRange::preset('this_week');

    $text = (new WeeklyAttributionSummary(AttributionReport::for($range), StreamMetrics::forRange($range)))->text();

    expect($text)->toContain(implode("\n", [
        '',
        'Twitch streams (participation ≈ unique chatters ÷ average viewers, an approximation):',
        '• '.$session->utmCampaign().': avg 40 viewers, peak 50, 12 unique chatters, ≈ 30% participation, 10 clicks, 1 enquiry, 10.0% conversion',
        '',
        'Full report: ',
    ]));
});

test('a week with streams but no clicks still reports the audience', function () {
    $session = liveSession('111');
    samples($session, 5);
    $range = DateRange::preset('this_week');

    $text = (new WeeklyAttributionSummary(AttributionReport::for($range), StreamMetrics::forRange($range)))->text();

    expect($text)->toContain("No short-link clicks and no enquiries that week.\n\nTwitch streams")
        ->toContain('avg 5 viewers, peak 5, 0 unique chatters, ≈ 0% participation, 0 clicks, 0 enquiries, — conversion');
});

test('the posted weekly summary includes last week\'s streams', function () {
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
    config(['are.weekly_summary.webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    $session = liveSession('111', startedAt: '2026-10-01 19:00:00');
    samples($session, 9);

    (new PostWeeklyAttributionSummary)->handle();

    expect(Http::recorded()[0][0]['text'])->toContain('• 2026-10-01-stream-111: avg 9 viewers, peak 9');
});
