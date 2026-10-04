<?php

use App\Models\ModerationAction;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserBan;
use App\Moderation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

function moderator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function vote(Question $question, User $user, int $count = 1): void
{
    DB::table('question_votes')->insert(['question_id' => $question->id, 'user_id' => $user->id, 'count' => $count]);
}

test('a local ban blocks a Facebook-only user and is logged', function () {
    $mod = moderator();
    $target = User::create(['name' => 'Facebook Troll', 'facebook_id' => 'fb-9']);

    Moderation::ban($mod, $target, null, 'spam');

    expect($target->isBanned())->toBeTrue()
        ->and($target->canSubmitQuestion())->toBeFalse()
        ->and(ModerationAction::where('action', 'user.banned')->first()->details)->toBe(['minutes' => null, 'reason' => 'spam']);
});

test('a timeout expires on its own', function () {
    $target = User::factory()->create();
    Moderation::ban(moderator(), $target, 10);

    expect($target->isBanned())->toBeTrue();

    $this->travel(11)->minutes();
    expect($target->isBanned())->toBeFalse();
});

test('lifting a ban unblocks the user and keeps the record', function () {
    $mod = moderator();
    $target = User::factory()->create();
    Moderation::ban($mod, $target, null);

    expect(Moderation::unban($mod, $target))->toBe(1)
        ->and($target->isBanned())->toBeFalse()
        ->and(UserBan::count())->toBe(1)
        ->and(UserBan::first()->lifted_at)->not->toBeNull();
});

test('viewers cannot ban, and nobody can ban a broadcaster or themselves', function () {
    $mod = moderator();

    expect(fn () => Moderation::ban(User::factory()->create(), User::factory()->create(), 10))->toThrow(AuthorizationException::class)
        ->and(fn () => Moderation::ban($mod, User::factory()->create(['twitch_id' => '1000']), 10))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Moderation::ban($mod, $mod, 10))->toThrow(InvalidArgumentException::class);

    expect(UserBan::count())->toBe(0);
});

test('a banned moderator loses moderator powers', function () {
    $mod = moderator();
    Moderation::ban(User::factory()->create(['twitch_id' => '1000']), $mod, null);

    expect(fn () => Moderation::ban($mod, User::factory()->create(), 10))->toThrow(AuthorizationException::class);
    $this->actingAs($mod)->get('/moderation')->assertRedirect('/?banned=1');
});

test('merging moves votes to the kept question without double-counting', function () {
    $mod = moderator();
    [$a, $b, $c] = User::factory()->count(3)->create();
    $keep = Question::factory()->create(['question' => 'Sing about tea']);
    $dupe = Question::factory()->create(['question' => 'sing about TEA']);
    vote($keep, $a);
    vote($dupe, $a);      // already voted on the kept question: not moved
    vote($dupe, $b);      // moves
    vote($dupe, $c, -1);  // moves, downvotes too

    Moderation::mergeQuestions($mod, $dupe, $keep);

    expect(Question::find($dupe->id))->toBeNull()
        ->and($keep->voteCount())->toBe(1)
        ->and(DB::table('question_votes')->where('question_id', $keep->id)->count())->toBe(3)
        ->and(ModerationAction::where('action', 'question.merged')->first()->details)
        ->toMatchArray(['duplicate_id' => $dupe->id, 'votes_moved' => 2]);
});

test('a question cannot be merged into itself or an archived one', function () {
    $mod = moderator();
    $q = Question::factory()->create();
    $archived = Question::factory()->create(['archived_at' => now()]);

    expect(fn () => Moderation::mergeQuestions($mod, $q, $q))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Moderation::mergeQuestions($mod, $q, $archived))->toThrow(InvalidArgumentException::class);
});

test('a moderator deleting someone else\'s question is logged with its text; an author deleting their own is not', function () {
    $mod = moderator();
    $author = User::factory()->create();
    $theirs = Question::factory()->for($author)->create(['question' => 'Rude words']);
    $own = Question::factory()->for($author)->create();

    Moderation::deleteQuestion($mod, $theirs);
    Moderation::deleteQuestion($author, $own);

    expect(Question::count())->toBe(0)
        ->and(ModerationAction::count())->toBe(1)
        ->and(ModerationAction::first()->details['question'])->toBe('Rude words');
});

test('setting and clearing the topic are logged', function () {
    $mod = moderator();
    $this->actingAs($mod);

    Volt::test('topic')->set('topic', 'Songs about kale')->call('save')->call('clear');

    expect(ModerationAction::pluck('action')->all())->toBe(['topic.set', 'topic.cleared'])
        ->and(Topic::current())->toBeNull();
});

test('the moderation page is for moderators only', function () {
    $this->actingAs(User::factory()->create())->get('/moderation')->assertForbidden();

    Question::factory()->create(['question' => 'Queue item']);
    $this->actingAs(moderator())->get('/moderation')->assertOk()->assertSee('Queue item')->assertSee('Audit log');
});

test('the card shows a delete button to the author but not to other viewers', function () {
    $author = User::factory()->create();
    $question = Question::factory()->for($author)->create();
    $props = ['question' => $question, 'voteCount' => 0, 'userVotes' => []];

    $this->actingAs($author);
    Volt::test('question-card', $props)->assertSeeHtml('aria-label="Delete question"');

    $this->actingAs(User::factory()->create());
    Volt::test('question-card', $props)->assertDontSeeHtml('aria-label="Delete question"');
});

test('logging out requires POST', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/logout')->assertMethodNotAllowed();
    $this->assertAuthenticated();
    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});
