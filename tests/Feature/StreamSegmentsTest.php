<?php

use App\Analytics\AttributionReport;
use App\Analytics\DateRange;
use App\Analytics\SegmentCounts;
use App\Analytics\StreamSegments;
use App\Chat\ChatCommandRegistry;
use App\IdentityProvider;
use App\Jobs\PostWeeklyAttributionSummary;
use App\Models\Question;
use App\Models\SongRequest;
use App\Models\StreamMarker;
use App\Models\StreamSession;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\WeeklyAttributionSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 22:00:00'));
});

function segmentSession(string $start = '2026-10-01 19:00:00', ?string $end = '2026-10-01 21:00:00', string $streamId = '111'): StreamSession
{
    return StreamSession::factory()->create([
        'broadcaster_id' => '1000',
        'twitch_stream_id' => $streamId,
        'started_at' => $start,
        'ended_at' => $end,
    ]);
}

function topicAt(string $topic, string $setAt, ?string $archivedAt): Topic
{
    $t = Topic::create(['topic' => $topic]);
    $t->forceFill(['created_at' => $setAt, 'updated_at' => $setAt, 'archived_at' => $archivedAt])->save();

    return $t;
}

function questionAt(string $at, ?string $source): void
{
    $q = Question::factory()->create(['source' => $source]);
    $q->forceFill(['created_at' => $at])->save();
}

function voteAt(?string $at): void
{
    DB::table('question_votes')->insert([
        'user_id' => User::factory()->create()->id,
        'question_id' => Question::factory()->create(['created_at' => '2026-09-01 00:00:00'])->id,
        'count' => 1,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function ballotAt(string $at): void
{
    DB::table('bus_ballots')->insert(['provider' => 'twitch', 'status' => 'counted', 'created_at' => $at, 'updated_at' => $at]);
}

function publicationAt(string $at, ?string $vetoedAt = null): void
{
    DB::table('bus_publications')->insert([
        'game' => 'orkestera', 'mode' => 'vote', 'verb' => 'build', 'votes' => 3, 'total_votes' => 5,
        'vetoed_at' => $vetoedAt, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

function approvalAt(string $status, string $decidedAt): void
{
    DB::table('bus_approvals')->insert([
        'game' => 'orkestera', 'mode' => 'freetext', 'verb' => 'task', 'action_key' => Str::random(8), 'votes' => 2, 'total_votes' => 4,
        'status' => $status, 'expires_at' => $decidedAt, 'decided_at' => $decidedAt, 'created_at' => $decidedAt, 'updated_at' => $decidedAt,
    ]);
}

function songAt(string $at): void
{
    SongRequest::factory()->create()->forceFill(['created_at' => $at])->save();
}

// --- recording the source and the vote time ----------------------------------

test('a question asked on the vote page is recorded as web, and !q as its chat platform', function () {
    $user = User::factory()->twitch('4145994')->create();
    $this->actingAs($user);

    Volt::test('vote')->set('question', 'From the web page')->call('saveQuestion')->assertHasNoErrors();
    app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', '4145994', 'viewer', 'm-1', '!q From Twitch chat');

    expect(Question::where('question', 'From the web page')->value('source'))->toBe('web')
        ->and(Question::where('question', 'From Twitch chat')->value('source'))->toBe('twitch');
});

test('a vote is dated when first cast, and a changed vote keeps that date', function () {
    $question = Question::factory()->create();
    $user = User::factory()->create();

    $question->recordVote($user, 1);
    $this->travel(10)->minutes();
    $question->recordVote($user, -1);

    $row = DB::table('question_votes')->sole();

    expect(substr((string) $row->created_at, 0, 19))->toBe('2026-10-01 22:00:00')
        ->and(substr((string) $row->updated_at, 0, 19))->toBe('2026-10-01 22:10:00')
        ->and((int) $row->count)->toBe(-1);
});

// --- segments ------------------------------------------------------------------

test('a session is split by topic, with a "(no topic)" stretch, and nothing outside it', function () {
    $session = segmentSession();
    topicAt('Laravel queues', '2026-10-01 18:30:00', '2026-10-01 19:30:00'); // set before the stream
    topicAt('Reverb', '2026-10-01 19:30:00', '2026-10-01 20:15:00');          // cleared at 20:15
    topicAt('Orkestera', '2026-10-01 20:40:00', null);                         // still set after the stream
    topicAt('Yesterday', '2026-09-30 19:00:00', '2026-09-30 20:00:00');       // another day

    $segments = StreamSegments::for($session)->segments;

    expect(array_map(fn ($s) => [$s['topic'], $s['from']->format('H:i'), $s['until']->format('H:i'), $s['driver']], $segments))->toBe([
        ['Laravel queues', '19:00', '19:30', 'human'],
        ['Reverb', '19:30', '20:15', 'human'],
        [null, '20:15', '20:40', 'human'],
        ['Orkestera', '20:40', '21:00', 'human'],
    ]);
});

test('a session with no topic at all is one "(no topic)" segment; a live one runs to now', function () {
    $session = segmentSession(end: null);

    $segments = StreamSegments::for($session)->segments;

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['topic'])->toBeNull()
        ->and($segments[0]['from']->format('H:i'))->toBe('19:00')
        ->and($segments[0]['until']->format('H:i'))->toBe('22:00');
});

test('each segment counts questions by source, votes, bus actions, song requests and clips; the stream total adds up', function () {
    $session = segmentSession();
    $other = segmentSession('2026-10-01 10:00:00', '2026-10-01 11:00:00', '222');
    topicAt('Reverb', '2026-10-01 19:00:00', '2026-10-01 20:00:00');
    topicAt('Orkestera', '2026-10-01 20:00:00', null);

    // Reverb, 19:00–20:00
    questionAt('2026-10-01 19:05:00', 'web');
    questionAt('2026-10-01 19:10:00', 'twitch');
    questionAt('2026-10-01 19:11:00', 'twitch');
    questionAt('2026-10-01 19:12:00', null); // asked before questions recorded their source
    voteAt('2026-10-01 19:20:00');
    voteAt('2026-10-01 19:59:59');
    songAt('2026-10-01 19:30:00');
    StreamMarker::factory()->for($session)->create(['created_at' => '2026-10-01 19:40:00']);

    // Orkestera, 20:00–21:00
    questionAt('2026-10-01 20:00:00', 'youtube'); // exactly at the change: the new topic's
    voteAt('2026-10-01 20:30:00');
    ballotAt('2026-10-01 20:10:00');
    ballotAt('2026-10-01 20:11:00');
    ballotAt('2026-10-01 20:12:00');
    publicationAt('2026-10-01 20:15:00');
    publicationAt('2026-10-01 20:16:00', vetoedAt: '2026-10-01 20:17:00');
    approvalAt('approved', '2026-10-01 20:20:00');
    approvalAt('rejected', '2026-10-01 20:21:00');

    // Outside the session, or on another one: not counted.
    questionAt('2026-10-01 21:00:00', 'web');
    voteAt('2026-10-01 18:59:59');
    voteAt(null); // cast before votes were dated
    StreamMarker::factory()->for($other)->create(['created_at' => '2026-10-01 19:45:00']);

    $stream = StreamSegments::for($session);
    [$reverb, $orkestera] = $stream->segments;

    expect($reverb['counts'])->toEqual(new SegmentCounts(['twitch' => 2, 'unknown' => 1, 'web' => 1], 2, 0, 0, 0, 0, 1, 1))
        ->and($reverb['counts']->questionsLabel())->toBe('1 web · 2 twitch chat · 1 unknown')
        ->and($orkestera['counts'])->toEqual(new SegmentCounts(['youtube' => 1], 1, 3, 2, 1, 1, 0, 0))
        ->and($stream->total)->toEqual(new SegmentCounts(['twitch' => 2, 'unknown' => 1, 'web' => 1, 'youtube' => 1], 3, 3, 2, 1, 1, 1, 1))
        ->and($stream->total->totalQuestions())->toBe(5)
        ->and($stream->total->chatQuestions())->toBe(['twitch' => 2, 'youtube' => 1]);
});

test('only sessions that started in the range are reported', function () {
    segmentSession('2026-09-20 19:00:00', '2026-09-20 20:00:00', 'old');
    segmentSession();

    expect(array_map(fn (StreamSegments $s) => $s->session->twitch_stream_id, StreamSegments::forRange(DateRange::preset('this_week'))))->toBe(['111']);
});

// --- where it shows -------------------------------------------------------------

test('/admin/attribution shows each stream\'s segments, totals and the human driver', function () {
    $session = segmentSession();
    topicAt('Reverb', '2026-10-01 19:00:00', null);
    questionAt('2026-10-01 19:05:00', 'web');
    questionAt('2026-10-01 19:06:00', 'twitch');
    voteAt('2026-10-01 19:20:00');
    voteAt(null);

    $this->actingAs(User::factory()->twitch('1000')->create())
        ->get('/admin/attribution')
        ->assertOk()
        ->assertSee('On ARE, by stream and topic')
        ->assertSee($session->utmCampaign())
        ->assertSeeInOrder(['Reverb', 'Thu 19:00–21:00', '2', '1 web · 1 twitch chat', '1', 'human'])
        ->assertSee('Stream total')
        ->assertSee('1 earlier vote was cast before votes were dated, so it is in no segment.');
});

test('the weekly summary has an ARE section per stream, with its busiest topic', function () {
    $session = segmentSession();
    topicAt('Reverb', '2026-10-01 19:00:00', '2026-10-01 20:00:00');
    topicAt('Orkestera', '2026-10-01 20:00:00', null);
    questionAt('2026-10-01 19:05:00', 'web');
    questionAt('2026-10-01 20:05:00', 'twitch');
    questionAt('2026-10-01 20:06:00', 'youtube');
    voteAt('2026-10-01 20:10:00');
    ballotAt('2026-10-01 20:11:00');
    songAt('2026-10-01 20:12:00');
    $range = DateRange::preset('this_week');

    $text = (new WeeklyAttributionSummary(AttributionReport::for($range), [], [], StreamSegments::forRange($range)))->text();

    expect($text)->toContain(implode("\n", [
        '',
        'On ARE, per stream (all driven by a human until the VTuber bridge lands):',
        '• '.$session->utmCampaign().': 3 questions (1 web · 1 twitch chat · 1 youtube chat), 1 vote, 1 bus ballot (0 published, 0 vetoed, 0 approved), 1 song request, 0 clips marked; busiest topic: "Orkestera" (2 questions)',
        '',
        'Full report: ',
    ]));
});

test('the posted weekly summary includes last week\'s streams on ARE, with no names', function () {
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
    config(['are.weekly_summary.webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    segmentSession();
    $asker = User::factory()->create(['name' => 'Ada Lovelace']);
    Question::factory()->for($asker)->create(['source' => 'web', 'question' => 'A secret question', 'created_at' => '2026-10-01 19:30:00']);

    (new PostWeeklyAttributionSummary)->handle();

    $text = Http::recorded()[0][0]['text'];

    expect($text)->toContain('On ARE, per stream')
        ->toContain('1 question (1 web)')
        ->not->toContain('Ada')
        ->not->toContain('secret');
});
