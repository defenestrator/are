<?php

use App\Events\QuestionArchived;
use App\Events\QuestionSubmitted;
use App\Events\TopicChanged;
use App\Events\VoteCast;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Moderation;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;
use Livewire\Volt\Volt;

// Reverb replaces wire:poll on the vote page (#5). The socket itself is not
// exercised here: these pin what is broadcast, where, and who listens.

function realtimeModerator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function votePage(): Testable
{
    return Livewire::test(FragmentAlias::encode('vote', resource_path('views/vote.blade.php')));
}

function card(Question $question, int $voteCount = 0): Testable
{
    return Volt::test('question-card', ['question' => $question, 'voteCount' => $voteCount, 'userVotes' => []]);
}

/**
 * @param  array<int, Channel>  $channels
 * @return list<string>
 */
function channelNames(array $channels): array
{
    return array_map(fn (Channel $channel) => $channel->name, $channels);
}

test('each event broadcasts a minimal payload on public channels via the broadcasts queue', function (ShouldBroadcast $event, array $channels, array $payload) {
    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and(channelNames($event->broadcastOn()))->toBe($channels)
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and($event->broadcastWith())->toBe($payload);

    foreach ($event->broadcastOn() as $channel) {
        expect($channel)->not->toBeInstanceOf(PrivateChannel::class)
            ->and($channel)->not->toBeInstanceOf(PresenceChannel::class);
    }
})->with([
    'QuestionSubmitted' => [new QuestionSubmitted(7), ['questions'], ['id' => 7]],
    'VoteCast' => [new VoteCast(7, 3, 12), ['questions'], ['question_id' => 7, 'votes' => 3, 'version' => 12]],
    'TopicChanged' => [new TopicChanged('Kale'), ['topic'], ['topic' => 'Kale']],
    'TopicChanged (cleared)' => [new TopicChanged(null), ['topic'], ['topic' => null]],
    'QuestionArchived' => [new QuestionArchived([7, 8]), ['questions'], ['ids' => [7, 8]]],
    'QuestionArchived (whole queue)' => [new QuestionArchived(null), ['questions'], ['ids' => null]],
]);

test('dispatching an event queues its broadcast on the broadcasts queue', function () {
    config(['broadcasting.default' => 'reverb']);
    Queue::fake();

    VoteCast::dispatch(1, 1, 1);

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
});

// #100 (Andras's repro): Laravel queues a broadcast job whatever the driver,
// so with BROADCAST_CONNECTION=log every vote left a row in `jobs`.

/**
 * Five votes, then one of each other event. Returns the number of broadcast jobs queued.
 */
function changeTheQueueAndCountBroadcasts(): int
{
    config(['queue.default' => 'database']);
    $question = Question::factory()->for(User::factory())->create();
    Question::cachedQueue();   // warm the cache, so the listener has something to retire

    foreach (User::factory()->count(5)->create() as $voter) {
        $question->recordVote($voter, 1);
    }
    QuestionSubmitted::dispatch($question->id);
    TopicChanged::dispatch('Kale');
    QuestionArchived::dispatch([$question->id]);

    // The non-broadcast listener still ran: the cached queue shows the votes.
    expect(Question::cachedQueue()['top']->first()->votes)->toEqual(5);

    return DB::table('jobs')->where('queue', 'broadcasts')->count();
}

test('with a log or null broadcaster, no event queues a broadcast job', function (string $connection) {
    config(['broadcasting.default' => $connection]);

    expect(changeTheQueueAndCountBroadcasts())->toBe(0);
})->with(['log', 'null']);

test('with Reverb configured, every event queues its broadcast job', function () {
    config(['broadcasting.default' => 'reverb']);

    expect(changeTheQueueAndCountBroadcasts())->toBe(8);
});

test('broadcastWhen follows the configured driver, not the connection name', function () {
    $event = new VoteCast(1, 1, 1);

    config(['broadcasting.default' => 'quiet', 'broadcasting.connections.quiet' => ['driver' => 'log']]);
    expect($event->broadcastWhen())->toBeFalse();

    config(['broadcasting.default' => 'live', 'broadcasting.connections.live' => ['driver' => 'reverb']]);
    expect($event->broadcastWhen())->toBeTrue();
});

test('submitting a question dispatches QuestionSubmitted', function () {
    Event::fake([QuestionSubmitted::class]);
    $this->actingAs(User::factory()->create());

    votePage()->set('question', 'Sing about kale')->call('saveQuestion')->assertHasNoErrors();

    $question = Question::sole();
    Event::assertDispatched(QuestionSubmitted::class, fn (QuestionSubmitted $e) => $e->questionId === $question->id);
});

test('a rejected submission dispatches nothing', function () {
    Event::fake([QuestionSubmitted::class]);
    $this->actingAs(User::factory()->create());

    votePage()->set('question', 'x')->call('saveQuestion')->assertHasErrors('question');

    Event::assertNotDispatched(QuestionSubmitted::class);
});

test('upvoting and downvoting dispatch VoteCast with the new total and no voter', function () {
    Event::fake([VoteCast::class]);
    $question = Question::factory()->create();
    DB::table('question_votes')->insert(['question_id' => $question->id, 'user_id' => User::factory()->create()->id, 'count' => 1]);
    $this->actingAs(User::factory()->create());

    card($question, 1)->call('upvote')
        ->assertSet('voteCount', 2)->assertSet('voteVersion', 1)
        ->assertSeeHtml('data-vote-version="1"');
    Event::assertDispatched(VoteCast::class, fn (VoteCast $e) => $e->questionId === $question->id && $e->votes === 2 && $e->version === 1);

    card($question->refresh(), 2)->call('downvote')->assertSet('voteCount', 0)->assertSet('voteVersion', 2);
    Event::assertDispatched(VoteCast::class, fn (VoteCast $e) => $e->votes === 0 && $e->version === 2);

    expect((new VoteCast($question->id, 0, 2))->broadcastWith())->not->toHaveKey('user_id');
});

test('every vote change bumps the question\'s vote version and broadcasts it with the total', function () {
    Event::fake([VoteCast::class]);
    $question = Question::factory()->create();
    [$a, $b] = User::factory()->count(2)->create();

    expect($question->recordVote($a, 1))->toBe(['votes' => 1, 'version' => 1])
        ->and($question->recordVote($b, 1))->toBe(['votes' => 2, 'version' => 2])
        ->and($question->recordVote($a, -1))->toBe(['votes' => 0, 'version' => 3])
        ->and($question->fresh()->vote_version)->toBe(3);

    $sent = Event::dispatched(VoteCast::class)->map(fn (array $args) => $args[0]->broadcastWith())->all();
    expect($sent)->toBe([
        ['question_id' => $question->id, 'votes' => 1, 'version' => 1],
        ['question_id' => $question->id, 'votes' => 2, 'version' => 2],
        ['question_id' => $question->id, 'votes' => 0, 'version' => 3],
    ]);
});

test('a vote that is refused dispatches nothing', function () {
    Event::fake([VoteCast::class]);
    $question = Question::factory()->create(['archived_at' => now()]);
    $this->actingAs(User::factory()->create());

    card($question)->call('upvote');

    Event::assertNotDispatched(VoteCast::class);
});

test('setting and clearing the topic dispatch TopicChanged, and clearing archives the queue', function () {
    Event::fake([TopicChanged::class, QuestionArchived::class]);
    $this->actingAs(realtimeModerator());

    Volt::test('topic')->set('topic', 'Songs about kale')->call('save');
    Event::assertDispatched(TopicChanged::class, fn (TopicChanged $e) => $e->topic === 'Songs about kale');
    Event::assertNotDispatched(QuestionArchived::class);

    Volt::test('topic')->call('clear');
    Event::assertDispatched(TopicChanged::class, fn (TopicChanged $e) => $e->topic === null);
    Event::assertDispatched(QuestionArchived::class, fn (QuestionArchived $e) => $e->questionIds === null);
});

test('deleting, merging and withdrawing questions dispatch QuestionArchived', function () {
    Event::fake([QuestionArchived::class, VoteCast::class]);
    $mod = realtimeModerator();
    $author = User::factory()->create();
    [$deleted, $duplicate, $target] = Question::factory()->count(3)->for($author)->create();
    DB::table('question_votes')->insert(['question_id' => $duplicate->id, 'user_id' => $mod->id, 'count' => 1]);

    Moderation::deleteQuestion($mod, $deleted);
    Event::assertDispatched(QuestionArchived::class, fn (QuestionArchived $e) => $e->questionIds === [$deleted->id]);

    Moderation::mergeQuestions($mod, $duplicate, $target);
    Event::assertDispatched(QuestionArchived::class, fn (QuestionArchived $e) => $e->questionIds === [$duplicate->id]);
    Event::assertDispatched(VoteCast::class, fn (VoteCast $e) => $e->questionId === $target->id && $e->votes === 1 && $e->version === 1);

    $this->actingAs($author);
    votePage()->call('clearUserQuestion');
    Event::assertDispatched(QuestionArchived::class, fn (QuestionArchived $e) => $e->questionIds === [$target->id]);
});

test('the vote page has no polling and listens on one questions channel in the browser', function () {
    $question = Question::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get('/vote')
        ->assertOk()
        ->assertDontSee('wire:poll', false)
        // The client hook (resources/js/live-queue.js) and what it writes into.
        ->assertSeeHtml('x-data="liveQueue"')
        ->assertSeeHtml('x-ref="top"')
        ->assertSeeHtml('data-vote-count="'.$question->id.'" data-vote-version="0"')
        ->assertSeeHtml('data-question-id="'.$question->id.'"')
        // Only the rare topic change goes through Livewire.
        ->assertSee('echo:topic,TopicChanged')
        ->assertDontSee('echo:questions')
        ->assertDontSee("questions.{$question->id}");
});

// Until production has Reverb (BROADCAST_CONNECTION=log, no keys), the build
// has no Echo and live-queue.js polls with $refresh instead.
test('without Reverb the vote page still renders the client hook that falls back to polling', function () {
    config(['broadcasting.default' => 'log', 'reverb.apps.apps.0.key' => null]);
    $question = Question::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get('/vote')
        ->assertOk()
        ->assertSeeHtml('x-data="liveQueue"')
        ->assertSeeHtml('data-vote-count="'.$question->id.'"')
        ->assertDontSee('wire:poll', false);
});

test('a fallback poll shows new totals: cards are keyed by vote_version, so changed ones remount', function () {
    $question = Question::factory()->create();
    $this->actingAs($viewer = User::factory()->create());
    $page = votePage()->assertSeeHtml('data-vote-count="'.$question->id.'" data-vote-version="0"');

    // Another viewer votes, with no socket to tell this page.
    $question->recordVote(User::factory()->create(), 1);

    $page->call('$refresh')
        ->assertSeeHtml('data-vote-count="'.$question->id.'" data-vote-version="1"');
});

test('a fallback poll with nothing changed reuses the cached queue and remounts no card', function () {
    Question::factory()->count(3)->create();
    $this->actingAs(User::factory()->create());
    $page = votePage();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page->call('$refresh');
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $q) => str_contains($q, 'sum(question_votes.count)')))->toHaveCount(0)
        ->and($page->html())->not->toContain('data-vote-version');
});

test('a remounted card shows the viewer\'s own vote as it is now, not as it was at page load', function () {
    $question = Question::factory()->create();
    $this->actingAs($viewer = User::factory()->create());
    $page = votePage();
    // A primary (accent) upvote button marks the viewer's own upvote.
    $upvoted = fn (string $html) => preg_match('/<button(?=[^>]*bg-\[var\(--color-accent\)\])(?=[^>]*aria-label="Upvote #'.$question->id.'")/', $html) === 1;
    expect($upvoted($page->html()))->toBeFalse();

    // The viewer votes from chat or another tab, then the page polls.
    $question->recordVote($viewer, 1);

    expect($upvoted($page->call('$refresh')->html()))->toBeTrue();
});

test('a vote costs other viewers no server request: no Livewire component listens for VoteCast (#33)', function () {
    $question = Question::factory()->create();
    $this->actingAs(User::factory()->create());

    // The card's count is updated in the browser from the payload, which must
    // carry the id the card is tagged with and the new total.
    card($question, 4)
        ->assertSeeHtml('data-vote-count="'.$question->id.'"')
        ->assertDontSee('echo:')
        ->dispatch('echo:questions,VoteCast', ['question_id' => $question->id, 'votes' => 5]);
})->throws(EventHandlerDoesNotExist::class);

test('the page has no Livewire handler for the questions channel either', function () {
    $this->actingAs(User::factory()->create());

    votePage()->dispatch('echo:questions,VoteCast', ['question_id' => 1, 'votes' => 1]);
})->throws(EventHandlerDoesNotExist::class);

test('deleting an account broadcasts that its questions left the queue', function () {
    Event::fake([QuestionArchived::class]);
    $author = User::factory()->create();
    $questions = Question::factory()->count(2)->for($author)->create();

    $this->actingAs($author);
    Volt::test('settings.delete-user-form')->call('deleteUser');

    expect(User::find($author->id))->toBeNull();
    Event::assertDispatched(QuestionArchived::class, fn (QuestionArchived $e) => $e->questionIds === $questions->pluck('id')->all());
});

test('a TopicChanged broadcast updates the topic shown to viewers', function () {
    $this->actingAs(User::factory()->create());
    $topic = Volt::test('topic')->assertSee('Be funny.');

    Topic::set('Songs about kale');

    $topic->dispatch('echo:topic,TopicChanged', ['topic' => 'Songs about kale'])->assertSee('Songs about kale');
});

test('viewers refreshing together share one pair of queue queries', function () {
    Question::factory()->count(3)->create();
    $this->actingAs(User::factory()->create());

    $aggregates = function (callable $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();

        return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'sum(question_votes.count)'))->count();
    };

    expect($aggregates(fn () => votePage()))->toBe(2)
        ->and($aggregates(fn () => votePage()))->toBe(0);
});

test('a new question, vote or removal retires the cached queue at once', function () {
    $question = Question::factory()->create(['question' => 'First']);
    $this->actingAs($viewer = User::factory()->create());
    votePage()->assertSee('First');

    // Through the real events (not faked), the way the app changes the queue.
    $page = votePage();
    $new = $viewer->questions()->create(['question' => 'Second']);
    QuestionSubmitted::dispatch($new->id);
    $page->call('$refresh')->assertSee('Second');

    $question->recordVote($viewer, 1);
    expect(Question::cachedQueue()['top']->first()->votes)->toEqual(1);

    $question->delete();
    QuestionArchived::dispatch([$question->id]);
    votePage()->assertDontSee('First');
});

// Andras's repro on #48: a key per version left a dead row per vote in the
// database cache store, which only deletes an expired key when it is read.
test('versioned queue keys do not pile up in the database cache store', function () {
    config(['cache.default' => 'database']);
    Question::factory()->count(50)->for(User::factory())->create();
    foreach (range(1, 20) as $i) {
        Question::cachedQueue();
        Question::forgetCachedQueue();
        $this->travel(2)->seconds();
    }
    Question::cachedQueue();

    expect(DB::table('cache')->where('key', 'like', '%questions.queue%')->count())->toBeLessThanOrEqual(2);
});

test('lists stored late by a reader that saw an older version are rebuilt', function () {
    $question = Question::factory()->create(['question' => 'Before']);

    // A slow reader builds lists, the queue changes and the version is bumped,
    // and only then does the slow reader store its pre-change lists.
    $old = Cache::get('questions.queue-version') ?? Question::forgetCachedQueue();
    $stale = Question::cachedQueue();
    $question->update(['question' => 'After']);
    Question::forgetCachedQueue();
    Cache::put('questions.queue', ['version' => $old, 'lists' => $stale], 60);

    expect(Question::cachedQueue()['top']->first()->question)->toBe('After');
});

test('a cleared version key never serves an old copy', function () {
    $question = Question::factory()->create(['question' => 'Before']);
    Question::cachedQueue();

    $question->update(['question' => 'After']);
    Cache::forget('questions.queue-version');

    expect(Question::cachedQueue()['top']->first()->question)->toBe('After');
});

test('deleting an account broadcasts new totals for the questions it had voted on', function () {
    Event::fake([VoteCast::class, QuestionArchived::class]);
    $leaver = User::factory()->create();
    $stayer = User::factory()->create();
    $question = Question::factory()->for($stayer)->create();
    $question->recordVote($stayer, 1);
    $question->recordVote($leaver, 1);
    $archived = Question::factory()->for($stayer)->create(['archived_at' => now()]);
    DB::table('question_votes')->insert(['question_id' => $archived->id, 'user_id' => $leaver->id, 'count' => 1]);

    $this->actingAs($leaver);
    Volt::test('settings.delete-user-form')->call('deleteUser');

    expect($question->fresh()->vote_version)->toBe(3);
    Event::assertDispatched(VoteCast::class, fn (VoteCast $e) => $e->questionId === $question->id && $e->votes === 1 && $e->version === 3);
    Event::assertNotDispatched(VoteCast::class, fn (VoteCast $e) => $e->questionId === $archived->id);
});

// Pins which rows the locking read covers and that it runs before the delete.
// SQLite's grammar drops FOR UPDATE; on PostgreSQL the lock clause is asserted.
test('deleting an account locks its own open questions and those it voted on, in id order, before the delete', function () {
    Event::fake([VoteCast::class, QuestionArchived::class]);
    $leaver = User::factory()->create();
    $other = User::factory()->create();
    $votedOn = Question::factory()->for($other)->create();
    $ownOpen = Question::factory()->for($leaver)->create();
    $ownArchived = Question::factory()->for($leaver)->create(['archived_at' => now()]);
    $untouched = Question::factory()->for($other)->create();
    $votedOn->recordVote($leaver, 1);

    $this->actingAs($leaver);
    DB::enableQueryLog();
    Volt::test('settings.delete-user-form')->call('deleteUser');
    $log = collect(DB::getQueryLog())->values();

    // whereKey() inlines integer ids into the SQL rather than binding them.
    $pattern = '/^select \* from "questions" where "questions"\."id" in \(([\d, ]*)\) order by "id" asc( for update)?$/';
    $lockAt = $log->search(fn (array $q) => preg_match($pattern, $q['query']) === 1);
    $deleteAt = $log->search(fn (array $q) => str_starts_with($q['query'], 'delete from "users"'));

    expect($lockAt)->not->toBeFalse();
    preg_match($pattern, $log[$lockAt]['query'], $m);
    $lockedIds = collect(explode(',', $m[1]))->map(fn ($id) => (int) trim($id))->sort()->values()->all();

    expect($lockAt)->toBeLessThan($deleteAt)
        ->and($lockedIds)->toBe([$votedOn->id, $ownOpen->id])
        ->and($lockedIds)->not->toContain($ownArchived->id)
        ->and($lockedIds)->not->toContain($untouched->id);

    if (DB::getDriverName() === 'pgsql') {
        expect($log[$lockAt]['query'])->toEndWith('for update');
    }

    Event::assertDispatched(QuestionArchived::class, fn (QuestionArchived $e) => $e->questionIds === [$ownOpen->id]);
});

test('a reader that finds the version key missing adds one and later readers share it', function () {
    Question::factory()->create();
    Cache::forget('questions.queue-version');

    Question::cachedQueue();
    $version = Cache::get('questions.queue-version');

    // A racing reader that already added a version is not overwritten.
    Question::cachedQueue();
    expect($version)->not->toBeNull()
        ->and(Cache::get('questions.queue-version'))->toBe($version)
        ->and(Cache::get('questions.queue')['version'])->toBe($version);
});
