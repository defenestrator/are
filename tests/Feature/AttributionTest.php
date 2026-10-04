<?php

use App\Analytics\AttributionReport;
use App\Analytics\AttributionRow;
use App\Analytics\DateRange;
use App\Models\Lead;
use App\Models\ShortLink;
use App\Models\ShortLinkClick;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use Carbon\CarbonImmutable;

// Wednesday of ISO week 40: this week runs Mon 28 Sep to Sun 4 Oct 2026.
beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00')));

function attributionBroadcaster(string $twitchId = '1000'): User
{
    return User::factory()->create(['twitch_id' => $twitchId]);
}

function streamLink(string $channel, string $stream): ShortLink
{
    return ShortLink::factory()->create(['utm_source' => $channel, 'utm_campaign' => $stream]);
}

function clicksAt(ShortLink $link, string ...$times): void
{
    foreach ($times as $time) {
        ShortLinkClick::factory()->for($link)->create(['clicked_at' => $time]);
    }
}

function leadAt(?string $channel, ?string $stream, string $time, array $attributes = []): Lead
{
    return Lead::factory()->create([
        'utm_source' => $channel,
        'utm_medium' => $channel ? 'stream' : null,
        'utm_campaign' => $stream,
        'created_at' => $time,
        'consented_at' => $time,
        ...$attributes,
    ]);
}

/**
 * The report as plain arrays: [channel, stream, clicks, leads, conversion].
 *
 * @param  list<AttributionRow>  $rows
 */
function rowsOf(array $rows): array
{
    return array_map(fn (AttributionRow $r) => [$r->channel, $r->stream, $r->clicks, $r->leads, $r->conversionLabel()], $rows);
}

/**
 * Three streams across two channels, with clicks and leads on both sides of
 * both week boundaries. youtube shares a campaign name with twitch, so the
 * grouping must key on channel and stream together.
 */
function seedAttribution(): void
{
    $twitchA = streamLink('twitch', '2026-09-29-orkestera');
    $twitchB = streamLink('twitch', '2026-10-01-pro-services');
    $youtubeA = streamLink('youtube', '2026-09-29-orkestera');

    clicksAt($twitchA,
        '2026-09-27 23:59:59', // last week
        '2026-09-29 20:00:00', '2026-09-29 20:05:00', '2026-09-29 20:10:00', '2026-09-30 09:00:00',
        '2026-10-05 00:00:00', // next week
    );
    clicksAt($twitchB, '2026-09-28 00:00:00', '2026-10-04 23:59:59');
    clicksAt($youtubeA, '2026-09-21 10:00:00'); // last week only

    leadAt('twitch', '2026-09-29-orkestera', '2026-09-29 20:30:00');
    leadAt('twitch', '2026-09-29-orkestera', '2026-09-30 10:00:00');
    leadAt('twitch', '2026-09-29-orkestera', '2026-09-27 12:00:00'); // last week
    leadAt('youtube', '2026-09-29-orkestera', '2026-10-02 08:00:00'); // no clicks this week
    leadAt(null, null, '2026-10-01 15:00:00'); // no short link
}

describe('the gate', function () {
    test('broadcasters of served channels get the page and the CSV', function (string $twitchId) {
        $this->actingAs(attributionBroadcaster($twitchId));

        $this->get('/admin/attribution')->assertOk()->assertSee('Attribution');
        $this->get('/admin/attribution.csv')->assertOk();
    })->with(['primary channel' => '1000', 'extra served channel' => '2000']);

    test('moderators are refused, because leads are business PII', function () {
        $mod = User::factory()->create();
        TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

        expect($mod->can('moderate'))->toBeTrue();

        $this->actingAs($mod)->get('/admin/attribution')->assertForbidden();
        $this->actingAs($mod)->get('/admin/attribution.csv')->assertForbidden();
    });

    test('viewers are refused', function () {
        $this->actingAs(User::factory()->create())->get('/admin/attribution')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/attribution.csv')->assertForbidden();
    });

    test('guests are refused with 403, not sent to log in', function () {
        $this->get('/admin/attribution')->assertForbidden();
        $this->get('/admin/attribution.csv')->assertForbidden();
    });

    test('a banned broadcaster is refused', function () {
        $broadcaster = attributionBroadcaster();
        TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => $broadcaster->twitch_id]);

        $this->actingAs($broadcaster)->get('/admin/attribution')->assertForbidden();
    });

    test('the gate is the leads rule, not a copy of it', function () {
        $mod = User::factory()->create();
        TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);
        $banned = attributionBroadcaster('2000');
        TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '2000']);

        foreach ([attributionBroadcaster(), $mod, $banned, User::factory()->create()] as $user) {
            expect($user->can('viewAttribution'))->toBe($user->can('viewAny', Lead::class));
        }
    });

    test('only broadcasters see the Leads and Attribution nav links', function () {
        $this->actingAs(attributionBroadcaster())->get('/vote')
            ->assertSee(route('leads.index'), false)
            ->assertSee(route('admin.attribution'), false);

        $mod = User::factory()->create();
        TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

        foreach ([$mod, User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/vote')
                ->assertDontSee(route('leads.index'), false)
                ->assertDontSee(route('admin.attribution'), false);
        }
    });
});

describe('date ranges', function () {
    test('this week and last week are ISO weeks, half-open at Monday midnight', function () {
        $now = CarbonImmutable::parse('2026-10-04 23:59:59'); // a Sunday

        $week = DateRange::preset('this_week', $now);
        expect($week->from->toDateTimeString())->toBe('2026-09-28 00:00:00')
            ->and($week->until->toDateTimeString())->toBe('2026-10-05 00:00:00');

        $last = DateRange::preset('last_week', $now);
        expect($last->from->toDateTimeString())->toBe('2026-09-21 00:00:00')
            ->and($last->until->toDateTimeString())->toBe('2026-09-28 00:00:00');
    });

    test('the last 30 days include today', function () {
        $range = DateRange::preset('last_30_days', CarbonImmutable::parse('2026-09-30 12:00:00'));

        expect($range->from->toDateString())->toBe('2026-09-01')
            ->and($range->lastDay()->toDateString())->toBe('2026-09-30');
    });

    test('a custom range includes its last day', function () {
        $range = DateRange::days('2026-09-29', '2026-09-30');

        expect($range->from->toDateTimeString())->toBe('2026-09-29 00:00:00')
            ->and($range->until->toDateTimeString())->toBe('2026-10-01 00:00:00');
    });
});

describe('aggregation', function () {
    test('this week counts per channel and stream, with totals', function () {
        seedAttribution();

        $report = AttributionReport::for(DateRange::preset('this_week'));

        expect(rowsOf($report->streams))->toBe([
            ['twitch', '2026-09-29-orkestera', 4, 2, '50.0%'],
            ['youtube', '2026-09-29-orkestera', 0, 1, '—'],
            ['twitch', '2026-10-01-pro-services', 2, 0, '0.0%'],
            [null, null, 0, 1, '—'],
        ])->and(rowsOf($report->channels()))->toBe([
            ['twitch', null, 6, 2, '33.3%'],
            ['youtube', null, 0, 1, '—'],
            [null, null, 0, 1, '—'],
        ])->and(rowsOf([$report->totals()]))->toBe([
            [null, null, 6, 4, '66.7%'],
        ]);
    });

    test('last week picks up only what fell before Monday midnight', function () {
        seedAttribution();

        $report = AttributionReport::for(DateRange::preset('last_week'));

        expect(rowsOf($report->streams))->toBe([
            ['twitch', '2026-09-29-orkestera', 1, 1, '100.0%'],
            ['youtube', '2026-09-29-orkestera', 1, 0, '0.0%'],
        ]);
    });

    test('the page shows the week by default, and presets and custom ranges change it', function () {
        seedAttribution();
        $this->actingAs(attributionBroadcaster());

        $this->get('/admin/attribution')
            ->assertOk()
            ->assertSee('Mon 28 Sep 2026 to Sun 4 Oct 2026')
            ->assertSeeInOrder(['By channel', 'twitch', '6', '2', '33.3%', 'youtube', '0', '1', '—', 'No short link'])
            ->assertSeeInOrder(['By stream', 'twitch', '2026-09-29-orkestera', '4', '2', '50.0%', 'Total', '6', '4', '66.7%'])
            ->assertSee('Short-link clicks')
            ->assertSee('Enquiries with consent');

        $this->get('/admin/attribution?preset=last_week')
            ->assertSee('Mon 21 Sep 2026 to Sun 27 Sep 2026')
            ->assertSeeInOrder(['Total', '2', '1', '50.0%']);

        $this->get('/admin/attribution?preset=last_30_days')
            ->assertSee('Tue 1 Sep 2026 to Wed 30 Sep 2026')
            // Through today: 1 + 4 twitch-a, 1 twitch-b, 1 youtube clicks; 3 twitch-a leads.
            ->assertSeeInOrder(['Total', '7', '3', '42.9%']);

        $this->get('/admin/attribution?from=2026-09-29&to=2026-09-29')
            ->assertSee('Tue 29 Sep 2026 to Tue 29 Sep 2026')
            ->assertSeeInOrder(['Total', '3', '1', '33.3%']);
    });

    test('a backwards range is rejected and falls back to this week', function () {
        $this->actingAs(attributionBroadcaster());

        $this->get('/admin/attribution?from=2026-09-30&to=2026-09-01')
            ->assertRedirect(route('admin.attribution'))
            ->assertSessionHasErrors('to');

        $this->get('/admin/attribution?preset=all_time')->assertSessionHasErrors('preset');
    });

    test('a click through /go is dated and lands in this week', function () {
        $link = streamLink('twitch', 'live-now');

        $this->get($link->url())->assertRedirect();

        expect(ShortLinkClick::where('short_link_id', $link->id)->sole()->clicked_at->toDateTimeString())->toBe('2026-09-30 12:00:00')
            ->and(rowsOf(AttributionReport::for(DateRange::preset('this_week'))->streams))->toBe([
                ['twitch', 'live-now', 1, 0, '0.0%'],
            ]);
    });

    test('clicks counted before clicks were dated are reported, not invented into a range', function () {
        ShortLink::factory()->create()->forceFill(['clicks' => 5])->save();
        $this->actingAs(attributionBroadcaster());

        $this->get('/admin/attribution')->assertSee('5 earlier short-link clicks were counted before clicks were dated');
    });
});

test('the empty state says there is nothing in the range', function () {
    $this->actingAs(attributionBroadcaster());

    $this->get('/admin/attribution')
        ->assertOk()
        ->assertSee('No short-link clicks or enquiries in this range.')
        ->assertDontSee('By stream');

    expect(AttributionReport::for(DateRange::preset('this_week'))->isEmpty())->toBeTrue()
        ->and(AttributionReport::for(DateRange::preset('this_week'))->totals()->conversionLabel())->toBe('—');
});

test('the CSV has the per-stream counts and a total, and nothing else', function () {
    seedAttribution();

    $response = $this->actingAs(attributionBroadcaster())->get('/admin/attribution.csv?preset=this_week');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('attribution-2026-09-28-to-2026-10-04.csv');

    expect(trim($response->streamedContent()))->toBe(implode("\n", [
        'channel,stream,short_link_clicks,enquiries_with_consent,conversion',
        'twitch,2026-09-29-orkestera,4,2,0.5000',
        'youtube,2026-09-29-orkestera,0,1,',
        'twitch,2026-10-01-pro-services,2,0,0.0000',
        '"no short link",,0,1,',
        'TOTAL,,6,4,0.6667',
    ]));
});

test('the CSV neutralises a UTM value that a spreadsheet would run as a formula', function () {
    clicksAt(streamLink('=HYPERLINK("x")', '+cmd'), '2026-09-30 09:00:00');

    $csv = $this->actingAs(attributionBroadcaster())->get('/admin/attribution.csv')->streamedContent();

    expect($csv)->toContain("\"'=HYPERLINK(\"\"x\"\")\",'+cmd,1,0,0.0000");
});

test('no lead PII reaches the page or the CSV', function () {
    $pii = [
        'name' => 'Persephone Quillfeather',
        'email' => 'persephone.quill@example.test',
        'company' => 'Quillfeather Widgets Ltd',
        'message' => 'Please call me about a confidential migration project.',
    ];
    clicksAt(streamLink('twitch', 'pii-stream'), '2026-09-30 09:00:00');
    leadAt('twitch', 'pii-stream', '2026-09-30 10:00:00', $pii);
    $this->actingAs(attributionBroadcaster());

    $page = $this->get('/admin/attribution')->assertOk()->assertSee('pii-stream');
    $csv = $this->get('/admin/attribution.csv')->streamedContent();

    foreach ($pii as $value) {
        $page->assertDontSee($value);
        expect($csv)->not->toContain($value);
    }
});
