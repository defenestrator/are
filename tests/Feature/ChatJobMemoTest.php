<?php

use App\Jobs\EventSub\HandleChatMessage;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\User;
use App\Support\RequestMemo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Andras's audit (#173): a chat job asked the ban question 3 times for !q
// and 2 for !vote. Queued jobs get their own memo scope.

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000', 'services.twitch.broadcaster_ids' => []]);
});

/** Dispatch a Twitch chat message through the queue, as the worker runs it. */
function queuedChat(string $text, string $chatterId = '4145994'): void
{
    dispatch(new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), [
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'chatter_user_id' => $chatterId,
        'chatter_user_login' => 'viewer32',
        'chatter_user_name' => 'viewer32',
        'message_id' => (string) Str::uuid(),
        'message' => ['text' => $text, 'fragments' => []],
        'message_type' => 'text',
        'badges' => [],
    ]));
}

/** @return Collection<int, string> */
function queriesWhile(Closure $run): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    DB::disableQueryLog();

    return collect(DB::getQueryLog())->pluck('query');
}

function banLookups(Collection $queries): int
{
    return $queries->filter(fn ($sql) => str_contains($sql, 'user_bans') || str_contains($sql, 'twitch_bans'))->count();
}

test('!q from chat asks the ban question once per job', function () {
    expect(config('queue.default'))->toBe('sync');
    User::factory()->twitch('4145994')->create();

    $queries = queriesWhile(fn () => queuedChat('!q Sing about kale'));

    expect(Question::where('question', 'Sing about kale')->exists())->toBeTrue()
        ->and(banLookups($queries))->toBe(1);
});

test('!vote from chat asks the ban question once per job', function () {
    User::factory()->twitch('4145994')->create();
    $question = Question::factory()->for(User::factory())->create();

    $queries = queriesWhile(fn () => queuedChat("!vote {$question->id}"));

    expect($question->voteCount())->toBe(1)
        ->and(banLookups($queries))->toBe(1);
});

test('a banned chatter is still refused, with the same single lookup', function () {
    User::factory()->twitch('4145994')->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '4145994']);

    $queries = queriesWhile(fn () => queuedChat('!q Let me in'));

    expect(Question::count())->toBe(0)->and(banLookups($queries))->toBe(1);
});

test('nothing memoised in one job leaks into the next', function () {
    User::factory()->twitch('4145994')->create();
    Topic::set('First topic');

    queuedChat('!q Before the ban');
    expect(app(RequestMemo::class)->enabled())->toBeFalse();

    // Written between jobs through the query builder, so no model event fires:
    // only a fresh scope per job can see it.
    DB::table('twitch_bans')->insert(['broadcaster_id' => '1000', 'twitch_user_id' => '4145994', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('topics')->update(['archived_at' => now()]);

    queuedChat('!q After the ban');

    expect(Question::pluck('question')->all())->toBe(['Before the ban'])
        ->and(Topic::current())->toBeNull();
});

test('a job that throws still closes its memo scope', function () {
    $failing = new class implements ShouldQueue
    {
        use Queueable;

        public function handle(): void
        {
            app(RequestMemo::class)->remember('bans.1', fn () => ['local' => false, 'twitch' => false]);

            throw new RuntimeException('boom');
        }
    };

    expect(fn () => dispatch($failing))->toThrow(RuntimeException::class);
    expect(app(RequestMemo::class)->enabled())->toBeFalse();
});

test('a sync job inside a web request does not switch the request\'s memo off', function () {
    $memo = app(RequestMemo::class);
    $memo->enable();   // the request's scope

    try {
        User::factory()->twitch('4145994')->create();
        queuedChat('!q Inside a request');

        expect($memo->enabled())->toBeTrue();
    } finally {
        $memo->reset();
    }
});
