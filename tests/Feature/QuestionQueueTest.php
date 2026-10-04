<?php

use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
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
    $primary = User::factory()->create(['twitch_id' => '1000']);
    $second = User::factory()->create(['twitch_id' => '2000']);
    $mod = User::factory()->create();
    $viewer = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '2000', 'twitch_user_id' => $mod->twitch_id]);

    expect($primary->isAdminUser())->toBeTrue()
        ->and($second->isAdminUser())->toBeTrue()
        ->and($mod->isAdminUser())->toBeTrue()
        ->and($viewer->isAdminUser())->toBeFalse();
});

test('clearing the topic archives questions and keeps their votes', function () {
    $admin = User::factory()->create(['twitch_id' => '1000']);
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

    Volt::test('topic')->set('topic', 'Hijacked')->call('save')->call('clear');

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

test('authors can delete their own question but not someone else\'s', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $mine = Question::factory()->for($author)->create();
    $theirs = Question::factory()->for($other)->create();

    $this->actingAs($author);
    Volt::test('question-card', ['question' => $theirs, 'voteCount' => 0, 'userVotes' => []])->call('deleteQuestion');
    Volt::test('question-card', ['question' => $mine, 'voteCount' => 0, 'userVotes' => []])->call('deleteQuestion');

    expect(Question::pluck('id')->all())->toBe([$theirs->id]);
});

test('the top-vote overlay renders with an empty queue', function () {
    $this->get('/top-vote')->assertOk();
});

test('the top-vote overlay shows the leading active question', function () {
    $question = Question::factory()->for(User::factory())->create(['question' => 'Sing about tea']);

    $this->get('/top-vote')->assertOk()->assertSee('Sing about tea');
});

test('a Facebook-only user can be created without Twitch fields', function () {
    $user = User::create(['name' => 'Facebook Person', 'facebook_id' => 'fb-1', 'email' => 'fb@example.com']);

    expect($user->isBanned())->toBeFalse()
        ->and($user->isAdminUser())->toBeFalse()
        ->and($user->canSubmitQuestion())->toBeTrue();
});
