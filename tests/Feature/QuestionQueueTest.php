<?php

use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Support\Facades\DB;
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
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->call('upvote', $question->id);

    expect($question->voteCount())->toBe(0);
});

test('active votes work and retries do not duplicate a viewers vote', function (bool $includeUserVotes) {
    $user = User::factory()->create();
    $question = Question::factory()->create();
    $this->actingAs($user);
    $props = ['question' => $question, 'voteCount' => 0];
    if ($includeUserVotes) {
        $props['userVotes'] = [];
    }

    $card = Volt::test('question-card', $props);
    $card->call('upvote', $question->id)->call('upvote', $question->id);
    expect($question->voteCount())->toBe(1);
    $card->call('downvote', $question->id)->call('downvote', $question->id);
    expect($question->voteCount())->toBe(-1)
        ->and(\DB::table('question_votes')->where('question_id', $question->id)->count())->toBe(1);
})->with(['top suggestions' => true, 'new ideas' => false]);

test('authors can delete their own question but not someone else\'s', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $mine = Question::factory()->for($author)->create();
    $theirs = Question::factory()->for($other)->create();

    $this->actingAs($author);
    Volt::test('question-card', ['question' => $theirs, 'voteCount' => 0, 'userVotes' => []])->call('deleteQuestion')->assertForbidden();
    Volt::test('question-card', ['question' => $mine, 'voteCount' => 0, 'userVotes' => []])->call('deleteQuestion');

    expect(Question::pluck('id')->all())->toBe([$theirs->id]);
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
