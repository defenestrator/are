<?php

use App\Enums\Overlay;
use App\Events\QuestionArchived;
use App\Events\QuestionSubmitted;
use App\Events\VoteCast;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Volt\Volt;

// The overlays follow the public `questions` channel in the browser
// (resources/js/live-overlay.js, #22), as the vote page does (#48). These pin
// the server side: the hook and what it writes into, that no Livewire
// component listens on the busy channel, and what a fallback refresh renders.

dataset('live overlays', [
    'queue' => [Overlay::Queue, 'recent', 5, 3],
    'vote' => [Overlay::Vote, 'top', 5, 3],
    'top-vote' => [Overlay::TopVote, 'top', 1, 1],
]);

function liveOverlayPage(Overlay $overlay, string $layout = 'horizontal'): TestResponse
{
    $token = OverlayToken::issue($overlay);

    return test()->get(route('overlay.show', ['overlay' => $overlay->value, 'layout' => $layout, 'token' => $token]));
}

test('each live overlay renders the client hook, with its mode and the size of its slice', function (Overlay $overlay, string $mode, int $horizontal, int $vertical) {
    $question = Question::factory()->create();

    foreach (['horizontal' => $horizontal, 'vertical' => $vertical] as $layout => $limit) {
        liveOverlayPage($overlay, $layout)
            ->assertOk()
            ->assertDontSee('wire:poll', false)
            ->assertSeeHtml('x-data="liveOverlay" data-live="on" data-live-mode="'.$mode.'" data-live-limit="'.$limit.'"')
            ->assertSeeHtml('x-ref="list"')
            ->assertSeeHtml('data-question-id="'.$question->id.'"')
            ->assertSeeHtml('data-vote-count="'.$question->id.'" data-vote-version="0"')
            // Only the rare topic change may go through Livewire.
            ->assertDontSee('echo:questions');
    }
})->with('live overlays');

test('ranked overlays mark their rank numbers for re-sorting in the browser', function () {
    Question::factory()->count(2)->create();

    liveOverlayPage(Overlay::Vote)->assertSeeHtml('<span data-rank');
    liveOverlayPage(Overlay::Queue)->assertDontSee('data-rank', false);
});

test('without Reverb the overlays still render the hook that falls back to polling', function (Overlay $overlay) {
    config(['broadcasting.default' => 'log', 'reverb.apps.apps.0.key' => null]);
    Question::factory()->create();

    liveOverlayPage($overlay)
        ->assertOk()
        ->assertSeeHtml('x-data="liveOverlay" data-live="on"')
        ->assertDontSee('wire:poll', false);
})->with([Overlay::Queue, Overlay::Vote, Overlay::TopVote]);

test('no overlay component handles the questions channel: a vote costs an overlay no server request', function (string $component) {
    OverlayToken::issue(Overlay::from(str_replace('overlays.', '', $component)));

    Volt::test($component)->dispatch('echo:questions,VoteCast', ['question_id' => 1, 'votes' => 1, 'version' => 1]);
})->with(['overlays.queue', 'overlays.vote', 'overlays.top-vote'])->throws(EventHandlerDoesNotExist::class);

test('the vote overlay follows the topic in the browser, and its refresh shows the new one', function () {
    OverlayToken::issue(Overlay::Vote);
    Question::factory()->create();
    Topic::set('Old topic');
    $component = Volt::test('overlays.vote')
        ->assertSee('Old topic')
        // live-overlay.js subscribes to `topic` for this overlay only (#125).
        ->assertSeeHtml('data-live-topic="on"');

    Topic::set('New topic');

    // TopicChanged makes live-overlay.js call $refresh (polling does the same).
    $component->call('$refresh')->assertSee('New topic');
});

test('only the vote overlay subscribes to the topic', function () {
    Question::factory()->create();

    liveOverlayPage(Overlay::Queue)->assertDontSee('data-live-topic', false);
    liveOverlayPage(Overlay::TopVote)->assertDontSee('data-live-topic', false);
});

test('a fallback or heartbeat refresh shows the new total and version', function () {
    OverlayToken::issue(Overlay::Vote);
    $question = Question::factory()->create();
    $component = Volt::test('overlays.vote')
        ->assertSeeHtml('data-vote-count="'.$question->id.'" data-vote-version="0">0</span>');

    // A vote with no socket to tell the overlay.
    $question->recordVote(User::factory()->create(), 1);

    $component->call('$refresh')
        ->assertSeeHtml('data-vote-count="'.$question->id.'" data-vote-version="1">1</span>');
});

test('overlay refreshes read the shared cached queue, so a burst costs no aggregate queries', function () {
    OverlayToken::issue(Overlay::Vote);
    OverlayToken::issue(Overlay::Queue);
    OverlayToken::issue(Overlay::TopVote);
    Question::factory()->count(6)->create();
    $components = [Volt::test('overlays.vote'), Volt::test('overlays.queue'), Volt::test('overlays.top-vote')];

    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach ($components as $component) {
        $component->call('$refresh');
    }
    $aggregates = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q) => str_contains($q, 'sum(question_votes.count)'));

    expect($aggregates)->toHaveCount(0);
});

test('the slices match the vote page: top by votes, recent by id, top-vote is the leader', function () {
    OverlayToken::issue(Overlay::Vote);
    OverlayToken::issue(Overlay::Queue);
    OverlayToken::issue(Overlay::TopVote);
    $voter = User::factory()->create();
    $older = Question::factory()->create(['question' => 'Older but popular']);
    $newer = Question::factory()->create(['question' => 'Newer and quiet']);
    $older->recordVote($voter, 1);

    Volt::test('overlays.vote')->assertSeeInOrder(['Older but popular', 'Newer and quiet']);
    Volt::test('overlays.queue')->assertSeeInOrder(['Newer and quiet', 'Older but popular']);
    Volt::test('overlays.top-vote')->assertSee('Older but popular')->assertDontSee('Newer and quiet');
});

test('the questions channel carries nothing a public overlay does not already show', function () {
    // The socket is public and overlay tokens don't gate it, so the payloads
    // must stay ids, totals and versions: never question text or who voted.
    expect((new VoteCast(7, 3, 2))->broadcastWith())->toBe(['question_id' => 7, 'votes' => 3, 'version' => 2])
        ->and((new QuestionSubmitted(7))->broadcastWith())->toBe(['id' => 7])
        ->and((new QuestionArchived([7, 8]))->broadcastWith())->toBe(['ids' => [7, 8]])
        ->and((new QuestionArchived(null))->broadcastWith())->toBe(['ids' => null]);
});
