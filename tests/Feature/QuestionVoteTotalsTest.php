<?php

use App\Models\Question;
use App\Models\QuestionVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #179: question_votes had no index leading with question_id, so each vote's
// re-count under the row lock, and both queue lists, scanned every vote ever
// cast. The lists are now a correlated subquery over open questions.

/**
 * The lists exactly as they were queried before #179, kept here as the
 * reference the new queries must match.
 *
 * @return Collection<int, Question>
 */
function legacyQueueList(string $order, int $limit = 50): Collection
{
    return Question::query()
        ->active()
        ->leftJoin('question_votes', 'questions.id', '=', 'question_votes.question_id')
        ->selectRaw('questions.*, coalesce(sum(question_votes.count), 0) as votes')
        ->orderBy($order, 'desc')
        ->groupBy('questions.id')
        ->limit($limit)
        ->get();
}

/**
 * @param  list<int>  $votes  one +1 or -1 per voter
 */
function questionWithVotes(array $votes, array $attributes = []): Question
{
    $question = Question::factory()->create($attributes);
    foreach ($votes as $count) {
        DB::table('question_votes')->insert(['question_id' => $question->id, 'user_id' => User::factory()->create()->id, 'count' => $count]);
    }

    return $question;
}

/**
 * @return array<int, mixed> id => votes, in list order
 */
function totalsInOrder(Collection $questions): array
{
    return $questions->mapWithKeys(fn (Question $q) => [$q->id => $q->votes])->all();
}

test('the lists return the same questions, totals and order as the old join', function () {
    // Distinct totals, so the old and new order are both fully determined.
    questionWithVotes([1, 1, 1]);              // 3
    questionWithVotes([]);                     // 0, no votes at all
    questionWithVotes([-1, -1]);               // -2
    questionWithVotes([1, -1, 1, 1, 1, 1]);    // 4, mixed up and down
    questionWithVotes([1, 1, 1, 1, 1, 1]);     // 6
    questionWithVotes([-1]);                   // -1
    questionWithVotes([1]);                    // 1
    questionWithVotes([1, 1, 1, 1, 1, 1, 1, 1, 1], ['archived_at' => now()]);   // archived: in neither list

    $top = Question::getSortedQuestions();
    $recent = Question::getRecentQuestions();

    expect($top)->toHaveCount(7)
        ->and(totalsInOrder($top))->toBe(totalsInOrder(legacyQueueList('votes')))
        ->and(totalsInOrder($recent))->toBe(totalsInOrder(legacyQueueList('id')))
        ->and(array_values(totalsInOrder($top)))->toEqual([6, 4, 3, 1, 0, -1, -2]);
});

test('with ties and a limit, the top list holds the same totals as the old join', function () {
    foreach ([[1, 1], [1, 1], [1], [], [-1], [1, 1, 1]] as $votes) {
        questionWithVotes($votes);
    }

    // Tied questions may come back in either order from either query; the
    // multiset of totals in the first four places cannot differ.
    expect(array_values(totalsInOrder(Question::getSortedQuestions(4))))
        ->toBe(array_values(totalsInOrder(legacyQueueList('votes', 4))));
});

test('question_votes has an index leading with question_id', function () {
    $leading = collect(Schema::getIndexes('question_votes'))->map(fn (array $index) => $index['columns'][0]);

    expect($leading)->toContain('question_id');
});

/**
 * 100 voters on each of 200 questions, so the planner has a real choice to make.
 */
function seedVotesForPlanner(): Question
{
    $users = User::factory()->count(100)->create();
    $questions = Question::factory()->count(200)->for($users->first())->create();
    DB::statement('insert into question_votes (user_id, question_id, count) select u.id, q.id, 1 from users u cross join questions q');
    DB::statement('analyze question_votes');

    return $questions->first();
}

/**
 * @param  callable(): mixed  $run
 */
function explainFirstQuery(string $from, callable $run): string
{
    $captured = null;
    DB::listen(function ($query) use (&$captured, $from) {
        $captured ??= str_contains($query->sql, $from) ? $query : null;
    });

    $run();

    return collect(DB::select('explain '.$captured->sql, $captured->bindings))->pluck('QUERY PLAN')->implode("\n");
}

test('on PostgreSQL, the re-count under the row lock uses the question_id index', function () {
    $question = seedVotesForPlanner();

    $plan = explainFirstQuery('from "question_votes"', fn () => $question->voteCount());

    expect($plan)->toContain('question_votes_question_id_index')
        ->and($plan)->not->toContain('Seq Scan on question_votes');
})->skip(
    fn () => DB::connection()->getDriverName() !== 'pgsql',
    'EXPLAIN plans are PostgreSQL-specific. The tests-pgsql CI job runs this.',
);

test('on PostgreSQL, the queue lists read votes through the index, not a scan of every vote', function (string $list) {
    seedVotesForPlanner();

    $plan = explainFirstQuery('from "questions"', fn () => Question::$list());

    expect($plan)->toContain('question_votes_question_id_index')
        ->and($plan)->not->toContain('Seq Scan on question_votes');
})->with(['getSortedQuestions', 'getRecentQuestions'])->skip(
    fn () => DB::connection()->getDriverName() !== 'pgsql',
    'EXPLAIN plans are PostgreSQL-specific. The tests-pgsql CI job runs this.',
);

test('voteCount and the lists agree on every question', function () {
    $questions = collect([[1, 1, -1], [], [-1, -1, -1], [1]])->map(fn ($votes) => questionWithVotes($votes));

    $listed = Question::getRecentQuestions()->keyBy('id');

    foreach ($questions as $question) {
        expect((int) $listed[$question->id]->votes)->toBe($question->voteCount());
    }

    expect(QuestionVote::count())->toBe(7);
});
