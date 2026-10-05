<?php

use App\Events\QuestionSubmitted;
use App\Exceptions\QuestionRejected;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\QuestionQueue;
use App\TwitchSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Livewire\Volt\Volt;

function subscribe(User $user, TwitchSubscription $tier, string $broadcasterId = '1000'): void
{
    UserTwitchSubscription::create([
        'user_id' => $user->id,
        'broadcaster_id' => $broadcasterId,
        'twitch_subscription' => $tier,
    ]);
}

test('non-subscribers can submit without limit, even with no topic set', function () {
    $user = User::factory()->create();
    Question::factory()->count(20)->for($user)->create();

    expect(Topic::current())->toBeNull()
        ->and($user->canSubmitQuestion())->toBeTrue();
});

test('subscribers are capped by tier and need a topic', function () {
    $user = User::factory()->create();
    subscribe($user, TwitchSubscription::Tier1);

    expect($user->canSubmitQuestion())->toBeFalse();

    Topic::set('Songs about kale');
    expect($user->canSubmitQuestion())->toBeTrue();

    Question::factory()->count(6)->for($user)->create();
    expect($user->canSubmitQuestion())->toBeFalse();
});

test('subscriptions to a second configured channel count', function () {
    $user = User::factory()->create();
    subscribe($user, TwitchSubscription::Tier3, '2000');

    expect($user->getHighestSubscription())->toBe(TwitchSubscription::Tier3);
});

test('archived questions do not count toward a subscriber cap', function () {
    $user = User::factory()->create();
    subscribe($user, TwitchSubscription::Tier1);
    Topic::set('First');
    Question::factory()->count(6)->for($user)->create();

    Topic::archiveAll();
    Topic::set('Second');

    expect($user->canSubmitQuestion())->toBeTrue();
});

test('banned and timed-out users cannot submit; expired timeouts can', function () {
    $user = User::factory()->create();

    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => $user->twitch_id]);
    expect($user->isBanned())->toBeTrue()->and($user->canSubmitQuestion())->toBeFalse();

    TwitchBan::query()->update(['ends_at' => now()->addMinutes(10)]);
    expect($user->isBanned())->toBeTrue();

    TwitchBan::query()->update(['ends_at' => now()->subMinute()]);
    expect($user->isBanned())->toBeFalse()->and($user->canSubmitQuestion())->toBeTrue();
});

test('a ban on a channel this app does not serve is ignored', function () {
    $user = User::factory()->create();
    TwitchBan::create(['broadcaster_id' => '9999', 'twitch_user_id' => $user->twitch_id]);

    expect($user->isBanned())->toBeFalse();
});

test('a banned user is signed out of the vote page', function () {
    $user = User::factory()->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => $user->twitch_id]);

    $this->actingAs($user)->get('/vote')->assertRedirect('/?banned=1');
    $this->assertGuest();
});

test('broadcasters on either channel and their moderators are admins', function () {
    $primary = User::factory()->twitch('1000')->create();
    $second = User::factory()->twitch('2000')->create();
    $mod = User::factory()->create();
    $viewer = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '2000', 'twitch_user_id' => $mod->twitch_id]);

    expect($primary->isAdminUser())->toBeTrue()
        ->and($second->isAdminUser())->toBeTrue()
        ->and($mod->isAdminUser())->toBeTrue()
        ->and($viewer->isAdminUser())->toBeFalse();
});

test('clearing the topic archives questions and keeps their votes', function () {
    $admin = User::factory()->twitch('1000')->create();
    $viewer = User::factory()->create();
    Topic::set('Old topic');
    $question = Question::factory()->for($viewer)->create();
    \DB::table('question_votes')->insert(['question_id' => $question->id, 'user_id' => $viewer->id, 'count' => 1]);

    $this->actingAs($admin);
    Volt::test('topic')->call('clear');

    expect(Topic::current())->toBeNull()
        ->and(Question::getSortedQuestions())->toHaveCount(0)
        ->and(Question::count())->toBe(1)
        ->and($question->fresh()->archived_at)->not->toBeNull()
        ->and($question->voteCount())->toBe(1);
});

test('a non-admin cannot clear or set the topic', function () {
    $this->actingAs(User::factory()->create());
    Topic::set('Keep me');

    Volt::test('topic')->set('topic', 'Hijacked')->call('save')->assertForbidden();
    Volt::test('topic')->call('clear')->assertForbidden();

    expect(Topic::current()->topic)->toBe('Keep me');
});

test('votes on archived questions are ignored', function () {
    $user = User::factory()->create();
    $question = Question::factory()->for($user)->create(['archived_at' => now()]);

    $this->actingAs($user);
    onVotePage()->call('upvote', $question->id);

    expect($question->voteCount())->toBe(0);
});

test('active votes work and retries do not duplicate a viewers vote', function () {
    $user = User::factory()->create();
    $question = Question::factory()->create();
    $this->actingAs($user);

    $page = onVotePage();
    $page->call('upvote', $question->id)->call('upvote', $question->id);
    expect($question->voteCount())->toBe(1);
    $page->call('downvote', $question->id)->call('downvote', $question->id);
    expect($question->voteCount())->toBe(-1)
        ->and(\DB::table('question_votes')->where('question_id', $question->id)->count())->toBe(1);
});

// --- Votes act only on an open question (#114, #180) -------------------------
//
// Since #180 the cards are Blade components and the vote page's actions take
// the question id from the browser. It is only ever looked up among open
// questions, and every rule still runs through QuestionQueue::vote.

test('a vote names exactly one open question and touches no other', function (string $action, int $expected) {
    [$voted, $other] = Question::factory()->count(2)->create();
    $this->actingAs(User::factory()->create());

    onVotePage()->call($action, $voted->id);

    expect($voted->voteCount())->toBe($expected)
        ->and($other->voteCount())->toBe(0)
        ->and(DB::table('question_votes')->where('question_id', $other->id)->exists())->toBeFalse();
})->with(['upvote' => ['upvote', 1], 'downvote' => ['downvote', -1]]);

test('a crafted vote naming a closed, deleted or made-up question does nothing', function (Closure $id) {
    $this->actingAs(User::factory()->create());
    $open = Question::factory()->create();

    onVotePage()->call('upvote', $id())->call('downvote', $id());

    expect(DB::table('question_votes')->count())->toBe(0)
        ->and($open->voteCount())->toBe(0);
})->with([
    'archived' => [fn () => Question::factory()->create(['archived_at' => now()])->id],
    'deleted' => [function () {
        $q = Question::factory()->create();
        $q->delete();

        return $q->id;
    }],
    'never existed' => [fn () => 999999],
    'not a number' => [fn () => '1 or 1=1'],
    'an array' => [fn () => [1]],
    'null' => [fn () => null],
]);

test('a vote answers with the new total and version, and re-renders nothing', function () {
    $question = Question::factory()->create();
    $this->actingAs(User::factory()->create());

    onVotePage()
        ->call('upvote', $question->id)
        ->assertDispatched('vote-recorded', question_id: $question->id, votes: 1, version: 1, vote: 1)
        ->assertNoRedirect();
});

test('the card\'s buttons name their own question, and only that one', function () {
    $question = Question::factory()->create();
    $this->actingAs(User::factory()->create());

    $html = cardHtml(Question::getSortedQuestions()->sole());

    expect($html)->toContain('wire:click="upvote('.$question->id.')"')
        ->toContain('wire:click="downvote('.$question->id.')"')
        ->toContain('data-question="'.$question->id.'"');
    expect(preg_match_all('/wire:click="(?:up|down)vote\((\d+)\)"/', $html, $ids))->toBe(2)
        ->and(array_unique($ids[1]))->toBe([(string) $question->id]);
});

test('authors can delete their own question but not someone else\'s', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $mine = Question::factory()->for($author)->create();
    $theirs = Question::factory()->for($other)->create();

    $this->actingAs($author);
    onVotePage()->call('deleteQuestion', $theirs->id)->assertForbidden();
    onVotePage()->call('deleteQuestion', $mine->id);

    expect(Question::pluck('id')->all())->toBe([$theirs->id]);
});

test('a crafted delete naming a closed or made-up question does nothing', function () {
    $author = User::factory()->create();
    $archived = Question::factory()->for($author)->create(['archived_at' => now()]);
    $this->actingAs($author);

    onVotePage()->call('deleteQuestion', $archived->id)->call('deleteQuestion', 999999)->call('deleteQuestion', 'x');

    expect(Question::find($archived->id))->not->toBeNull();
});

test('a Facebook-only user can be created without Twitch fields', function () {
    $user = User::factory()->facebook('fb-1')->create(['name' => 'Facebook Person', 'email' => 'fb@example.com']);

    expect($user->twitch_id)->toBeNull()->and($user->facebook_id)->toBe('fb-1');

    expect($user->isBanned())->toBeFalse()
        ->and($user->isAdminUser())->toBeFalse()
        ->and($user->canSubmitQuestion())->toBeTrue();
});

test('the vote page runs the same queries for one card as for a full queue', function (bool $isModerator) {
    $viewer = User::factory()->create();
    if ($isModerator) {
        TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $viewer->twitch_id]);
    }
    $this->actingAs($viewer);

    $queriesFor = function (int $questions) use ($isModerator) {
        Question::query()->delete();
        Question::factory()->count($questions)->for(User::factory())->create();
        // Factories skip the events that retire the vote page's cached queue.
        Question::forgetCachedQueue();

        // actingAs() reuses one User instance across requests; a real request
        // loads it fresh, so clear the per-instance once() memo between them.
        Once::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('/vote')->assertOk();
        DB::disableQueryLog();

        // Someone else's questions: only a moderator gets the delete button.
        $isModerator
            ? $response->assertSee('aria-label="Delete question"', false)
            : $response->assertDontSee('aria-label="Delete question"', false);

        return collect(DB::getQueryLog())->pluck('query');
    };

    // The first request loads the viewer's identities onto this shared test
    // user and later requests reuse them, so warm up before comparing counts.
    $queriesFor(1);
    $one = $queriesFor(1);
    $full = $queriesFor(50);

    expect($full->filter(fn (string $sql) => str_contains($sql, 'twitch_moderators'))->count())->toBeLessThan(5)
        ->and($full->count())->toBe($one->count());
})->with(['viewer' => false, 'moderator' => true]);

// #81: two submissions at once could both pass the subscriber cap. A true race
// cannot run in one process, so pin the order instead: the user row is locked,
// inside the same transaction, before the open questions are counted.
test('a submission locks the user row before counting their open questions', function () {
    $user = User::factory()->create();
    subscribe($user, TwitchSubscription::Tier1);
    Topic::set('Kale');
    Question::factory()->count(2)->for($user)->create();

    DB::enableQueryLog();
    QuestionQueue::submit($user, 'one more about kale');
    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();

    $lock = $queries->search(fn (string $sql) => preg_match('/from ["`]?users["`]? where ["`]?users["`]?\.["`]?id["`]? = \?/i', $sql) === 1);
    $count = $queries->search(fn (string $sql) => str_contains(strtolower($sql), 'count(*)') && str_contains($sql, 'questions'));
    $insert = $queries->search(fn (string $sql) => str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, 'questions'));

    expect($lock)->toBeInt()
        ->and($count)->toBeInt()
        ->and($insert)->toBeInt()
        ->and($lock)->toBeLessThan($count)
        ->and($count)->toBeLessThan($insert);

    // SQLite has no row locks (it locks the whole database for writes), so its
    // grammar drops FOR UPDATE. Production runs PostgreSQL, where it must be there.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(strtolower($queries[$lock]))->toContain('for update');
    }
});

test('the cap still holds once the lock is in place', function () {
    $user = User::factory()->create();
    subscribe($user, TwitchSubscription::Tier1);
    Topic::set('Kale');
    Question::factory()->count(5)->for($user)->create();

    QuestionQueue::submit($user, 'the sixth one');

    expect(fn () => QuestionQueue::submit($user, 'the seventh one'))->toThrow(QuestionRejected::class)
        ->and($user->questions()->active()->count())->toBe(6);
});

test('QuestionSubmitted is broadcast once, after the locked transaction commits, and not for a rejected submission', function () {
    $user = User::factory()->create();
    subscribe($user, TwitchSubscription::Tier1);
    Topic::set('Kale');
    Question::factory()->count(5)->for($user)->create();

    // RefreshDatabase wraps each test in a transaction, so "committed" means
    // back at the level the caller was at before submit() opened its own.
    $callerLevel = DB::transactionLevel();
    $dispatchedAt = [];
    Event::listen(QuestionSubmitted::class, function (QuestionSubmitted $e) use (&$dispatchedAt) {
        $dispatchedAt[$e->questionId] = DB::transactionLevel();
    });

    $question = QuestionQueue::submit($user, 'the sixth one');
    expect(fn () => QuestionQueue::submit($user, 'the seventh one'))->toThrow(QuestionRejected::class);

    expect($dispatchedAt)->toBe([$question->id => $callerLevel]);
});

// --- The viewer's own votes (#101) -------------------------------------------

/** Give $viewer a history of $count votes on questions that are no longer in the queue. */
function oldVotes(User $viewer, int $count): void
{
    $ids = Question::factory()->count($count)->create(['archived_at' => now()])->modelKeys();
    DB::table('question_votes')->insert(array_map(
        fn (int $id) => ['question_id' => $id, 'user_id' => $viewer->id, 'count' => 1],
        $ids,
    ));
}

/** Load /vote as $viewer and return the response body and the queries it ran. */
function loadVotePage($test, User $viewer): array
{
    Question::forgetCachedQueue();
    Once::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = $test->actingAs($viewer)->get('/vote')->assertOk()->getContent();
    DB::disableQueryLog();

    return [$html, collect(DB::getQueryLog())->pluck('query')];
}

test('the vote page does not grow with the viewer\'s old votes', function () {
    Question::factory()->count(5)->create();
    $fresh = User::factory()->create();
    $regular = User::factory()->create();
    oldVotes($regular, 300);

    // Livewire inlines its styles into the first page of the process only.
    loadVotePage($this, $fresh);
    [$freshHtml] = loadVotePage($this, $fresh);
    [$regularHtml] = loadVotePage($this, $regular);

    // Before #101 the regular viewer's page was ~51 KB bigger (every old vote
    // copied into every card). Allow a little for per-user text such as names.
    expect(strlen($regularHtml) - strlen($freshHtml))->toBeLessThan(512);
});

test('the vote page reads only the viewer\'s votes on the questions it shows, in one query', function () {
    $onPage = Question::factory()->count(5)->create();
    $viewer = User::factory()->create();
    oldVotes($viewer, 50);

    [, $none] = loadVotePage($this, User::factory()->create());
    [$html, $queries] = loadVotePage($this, $viewer);

    $viewerVoteQueries = $queries->filter(fn (string $sql) => str_contains($sql, 'question_votes') && str_contains($sql, '"user_id"'));
    expect($viewerVoteQueries)->toHaveCount(1)
        ->and($viewerVoteQueries->first())->toContain('"question_id" in (')
        // Fifty old votes cost no extra query.
        ->and($queries->count())->toBe($none->count());

    // The card snapshot carries one vote, not the viewer's whole history.
    expect($html)->not->toContain('userVotes');
});

test('each card shows the viewer\'s own vote on that question', function () {
    [$up, $down, $none] = Question::factory()->count(3)->create();
    $viewer = User::factory()->create();
    $up->recordVote($viewer, 1);
    $down->recordVote($viewer, -1);
    oldVotes($viewer, 3);

    [$html] = loadVotePage($this, $viewer);

    $pressed = fn (string $button, Question $q) => preg_match_all('/<button(?=[^>]*aria-pressed="true")(?=[^>]*aria-label="'.$button.' #'.$q->id.'")/', $html);

    // Each question is on the page twice (Top Suggestions and New Ideas).
    expect($pressed('Upvote', $up))->toBe(2)
        ->and($pressed('Downvote', $up))->toBe(0)
        ->and($pressed('Downvote', $down))->toBe(2)
        ->and($pressed('Upvote', $down))->toBe(0)
        ->and($pressed('Upvote', $none) + $pressed('Downvote', $none))->toBe(0);
});
