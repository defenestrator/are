<?php

use App\Analytics\AttributionReport;
use App\Analytics\DateRange;
use App\Jobs\PostWeeklyAttributionSummary;
use App\Models\Lead;
use App\Models\ShortLink;
use App\Models\ShortLinkClick;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\WeeklyAttributionSummary;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const SLACK_HOOK = 'https://hooks.slack.com/services/T000/B000/WEEKLY';

beforeEach(function () {
    // Monday of ISO week 41, at the scheduled time: last week is 2026-W40, Mon 28 Sep to Sun 4 Oct.
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
    config(['are.weekly_summary.webhook_url' => SLACK_HOOK]);
    Http::fake(['hooks.slack.com/*' => Http::response('ok'), 'discord.com/*' => Http::response(null, 204)]);
});

function weeklyLink(string $channel, string $stream): ShortLink
{
    return ShortLink::factory()->create(['utm_source' => $channel, 'utm_campaign' => $stream]);
}

function weeklyClicks(ShortLink $link, int $count, string $at = '2026-10-01 20:00:00'): void
{
    ShortLinkClick::factory()->count($count)->for($link)->create(['clicked_at' => $at]);
}

function weeklyLead(?ShortLink $link, string $at = '2026-10-01 21:00:00', array $attributes = []): Lead
{
    return Lead::factory()->create($attributes + [
        'short_link_id' => $link?->id,
        'utm_source' => $link?->utm_source,
        'utm_medium' => $link?->utm_medium,
        'utm_campaign' => $link?->utm_campaign,
        'created_at' => $at,
    ]);
}

/** The text of the one message posted to the webhook. */
function postedSummary(): string
{
    Http::assertSentCount(1);

    return Http::recorded()[0][0]['text'] ?? Http::recorded()[0][0]['content'];
}

test('it posts last week\'s totals, top stream, channels, streams and a link to the report', function () {
    $orkestera = weeklyLink('twitch', '2026-10-01-stream-111');
    $quiet = weeklyLink('twitch', '2026-09-29-stream-222');
    $youtube = weeklyLink('youtube', '2026-10-01-stream-111');

    weeklyClicks($orkestera, 40);
    weeklyClicks($quiet, 10);
    weeklyClicks($youtube, 20);
    weeklyLead($orkestera);
    weeklyLead($orkestera);
    weeklyLead($youtube);
    weeklyLead(null); // an enquiry without a short link

    // Outside the week: ignored.
    weeklyClicks($orkestera, 99, '2026-09-27 23:59:59');
    weeklyClicks($orkestera, 99, '2026-10-05 00:00:00');
    weeklyLead($orkestera, '2026-10-05 08:00:00');

    (new PostWeeklyAttributionSummary)->handle();

    expect(postedSummary())->toBe(implode("\n", [
        'EDOS weekly attribution, Mon 28 Sep 2026 to Sun 4 Oct 2026',
        'Total: 70 clicks, 4 enquiries, 5.7% conversion',
        'Top stream: twitch / 2026-10-01-stream-111 (40 clicks, 2 enquiries, 5.0% conversion)',
        '',
        'By channel:',
        '• twitch: 50 clicks, 2 enquiries, 4.0% conversion',
        '• youtube: 20 clicks, 1 enquiry, 5.0% conversion',
        '• no short link: 0 clicks, 1 enquiry, — conversion',
        '',
        'By stream:',
        '• twitch / 2026-10-01-stream-111: 40 clicks, 2 enquiries, 5.0% conversion',
        '• youtube / 2026-10-01-stream-111: 20 clicks, 1 enquiry, 5.0% conversion',
        '• twitch / 2026-09-29-stream-222: 10 clicks, 0 enquiries, 0.0% conversion',
        '• no short link: 0 clicks, 1 enquiry, — conversion',
        '',
        'Full report: '.route('admin.attribution', ['from' => '2026-09-28', 'to' => '2026-10-04']),
    ]));

    Http::assertSent(fn (Request $request) => $request->url() === SLACK_HOOK && $request->method() === 'POST');
});

test('it sends no lead PII, only aggregates', function () {
    $link = weeklyLink('twitch', '2026-10-01-stream-111');
    weeklyClicks($link, 3);
    weeklyLead($link, attributes: [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'company' => 'Analytical Engines Ltd',
        'message' => 'Our secret roadmap for 2027',
    ]);

    (new PostWeeklyAttributionSummary)->handle();

    $body = Http::recorded()[0][0]->body();

    expect($body)->not->toContain('Ada')
        ->not->toContain('Lovelace')
        ->not->toContain('ada@example.com')
        ->not->toContain('example.com')
        ->not->toContain('Analytical')
        ->not->toContain('secret roadmap')
        ->not->toContain('/leads')
        ->and(array_keys(json_decode($body, true)))->toBe(['text']);
});

test('an empty week posts a short "quiet week" message with the link', function () {
    weeklyClicks(weeklyLink('twitch', 'old'), 5, '2026-09-20 12:00:00'); // the week before

    (new PostWeeklyAttributionSummary)->handle();

    expect(postedSummary())->toBe(implode("\n", [
        'EDOS weekly attribution, Mon 28 Sep 2026 to Sun 4 Oct 2026',
        'No short-link clicks and no enquiries that week.',
        'Full report: '.route('admin.attribution', ['from' => '2026-09-28', 'to' => '2026-10-04']),
    ]));
});

test('it is a no-op with no webhook configured', function () {
    config(['are.weekly_summary.webhook_url' => null]);
    weeklyClicks(weeklyLink('twitch', 's'), 3);

    (new PostWeeklyAttributionSummary)->handle();

    Http::assertNothingSent();
    expect(DB::table('attribution_summaries')->count())->toBe(0);
});

test('it posts each ISO week once: a re-run or duplicate does not double-post', function () {
    (new PostWeeklyAttributionSummary)->handle();
    (new PostWeeklyAttributionSummary)->handle();
    $this->travel(3)->hours();
    (new PostWeeklyAttributionSummary)->handle();

    Http::assertSentCount(1);
    expect(DB::table('attribution_summaries')->sole())
        ->iso_week->toBe('2026-W40')
        ->posted_at->not->toBeNull();

    // The next Monday reports the next week.
    $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
    (new PostWeeklyAttributionSummary)->handle();

    Http::assertSentCount(2);
});

test('a failed post releases the week, so the retry posts it once', function () {
    // A host of its own: stubs match in the order they were faked, and beforeEach's Slack stub always succeeds.
    config(['are.weekly_summary.webhook_url' => 'https://hooks.flaky.example/weekly']);
    Http::fake(['hooks.flaky.example/*' => Http::sequence()->push('down', 500)->push('down', 500)->push('down', 500)->push('ok')]);

    expect(fn () => (new PostWeeklyAttributionSummary)->handle())->toThrow(RequestException::class);
    expect(DB::table('attribution_summaries')->count())->toBe(0);

    (new PostWeeklyAttributionSummary)->handle(); // the queue's retry
    (new PostWeeklyAttributionSummary)->handle(); // a duplicate

    // WebhookChannel tries each post 3 times: 3 failures, then 1 success.
    Http::assertSentCount(4);
    expect(DB::table('attribution_summaries')->whereNotNull('posted_at')->count())->toBe(1);
});

test('Discord webhooks get the same text as "content"', function () {
    config(['are.weekly_summary.webhook_url' => 'https://discord.com/api/webhooks/1/abc']);

    (new PostWeeklyAttributionSummary)->handle();

    expect(json_decode(Http::recorded()[0][0]->body(), true))->toHaveKey('content')->not->toHaveKey('text');
});

test('more than 10 streams are summed into one line, and the text fits Discord', function () {
    foreach (range(1, 14) as $i) {
        weeklyClicks(weeklyLink('twitch', 'stream-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)), 15 - $i);
    }

    (new PostWeeklyAttributionSummary)->handle();

    $text = postedSummary();

    expect($text)->toContain('• twitch / stream-10: 5 clicks')
        ->not->toContain('stream-11')
        ->toContain('• and 4 more: 10 clicks, 0 enquiries, 0.0% conversion')
        ->and(mb_strlen($text))->toBeLessThanOrEqual(WebhookChannel::MAX_LENGTH);
});

test('it is scheduled weekly on Monday at 09:00 in the app timezone, as a queued job', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event instanceof CallbackEvent && str_contains((string) $event->description, PostWeeklyAttributionSummary::class));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * 1')
        ->and($event->timezone)->toBe(config('app.timezone'))
        ->and(new PostWeeklyAttributionSummary)->toBeInstanceOf(ShouldQueue::class);
});

test('the schedule is skipped when no webhook is configured', function () {
    config(['are.weekly_summary.webhook_url' => null]);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->description, PostWeeklyAttributionSummary::class));

    expect($event->filtersPass($this->app))->toBeFalse();
});

test('attribution:weekly-summary queues a week on demand', function () {
    Queue::fake();

    $this->artisan('attribution:weekly-summary', ['--week' => '2026-09-16'])
        ->expectsOutputToContain('2026-W38')
        ->assertSuccessful();

    Queue::assertPushed(PostWeeklyAttributionSummary::class, fn ($job) => $job->weekStart === '2026-09-14');

    $this->artisan('attribution:weekly-summary', ['--week' => 'last tuesday'])->assertFailed();

    config(['are.weekly_summary.webhook_url' => null]);
    $this->artisan('attribution:weekly-summary')->assertFailed();
});

test('the summary notification only goes to the webhook channel', function () {
    $notification = new WeeklyAttributionSummary(AttributionReport::for(DateRange::preset('last_week')));

    expect($notification->via(new AnonymousNotifiable))->toBe([WebhookChannel::class]);
});
