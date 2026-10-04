<?php

use App\Exceptions\IdentityLinkException;
use App\Identities;
use App\IdentityProvider;
use App\Models\Identity;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\TwitchProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Volt\Volt;

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000', 'services.twitch.broadcaster_ids' => []]);
    Http::fake(['api.twitch.tv/*' => Http::response(['data' => []])]);
});

function providerAccount(string $id, string $name = 'Viewer', ?string $token = 'access-token'): SocialiteUser
{
    $account = (new SocialiteUser)->map([
        'id' => $id,
        'name' => $name,
        'nickname' => $name,
        'email' => null,
        'avatar' => "https://example.com/{$id}.png",
    ]);

    return $token === null ? $account : $account->setToken($token)->setRefreshToken('refresh-token')->setExpiresIn(3600);
}

/**
 * Make Socialite's $driver return $account from its callback.
 */
function fakeProviderAccount(string $driver, SocialiteUser $account): void
{
    $class = $driver === 'facebook' ? FacebookProvider::class : TwitchProvider::class;
    $provider = Mockery::mock($class);
    $provider->shouldReceive('user')->andReturn($account);
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect("https://{$driver}.example/oauth"));
    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
}

function voteAs(User $user, Question $question): void
{
    test()->actingAs($user);
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->call('upvote', $question->id);
}

// Sign-in

test('Twitch sign-in creates a user through an identity and stores its tokens encrypted', function () {
    fakeProviderAccount('twitch', providerAccount('42', 'Kale Fan'));

    $this->get('/twitch/auth')->assertRedirect('/vote');

    $user = User::sole();
    $identity = $user->identities()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Kale Fan')
        ->and($user->twitch_id)->toBe('42')
        ->and($user->twitch_avatar_url)->toBe('https://example.com/42.png')
        ->and($identity->provider)->toBe(IdentityProvider::Twitch)
        ->and($identity->access_token)->toBe('access-token')
        ->and($identity->refresh_token)->toBe('refresh-token')
        ->and($identity->token_expires_at)->not->toBeNull()
        ->and(DB::table('identities')->value('access_token'))->not->toContain('access-token');
});

test('signing in again finds the same user and refreshes the identity', function () {
    $user = User::factory()->twitch('42')->create(['name' => 'Old Name']);
    fakeProviderAccount('twitch', providerAccount('42', 'New Name', 'new-token'));

    $this->get('/twitch/auth')->assertRedirect('/vote');

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)
        ->and($user->fresh()->name)->toBe('New Name')
        ->and($user->identities()->sole()->access_token)->toBe('new-token');
});

test('Facebook sign-in creates a user through an identity and keeps no token', function () {
    fakeProviderAccount('facebook', providerAccount('fb-7', 'Face Person'));

    $this->get('/auth/facebook/callback')->assertRedirect('/vote');

    $identity = Identity::sole();
    expect($identity->provider)->toBe(IdentityProvider::Facebook)
        ->and($identity->provider_user_id)->toBe('fb-7')
        ->and($identity->access_token)->toBeNull()
        ->and($identity->user->facebook_id)->toBe('fb-7')
        ->and($identity->user->twitch_id)->toBeNull();
});

test('signing in with a linked account reaches the same user', function () {
    $user = User::factory()->twitch('42')->facebook('fb-7')->create();
    fakeProviderAccount('facebook', providerAccount('fb-7'));

    $this->get('/auth/facebook/callback')->assertRedirect('/vote');

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('a failed provider callback does not sign anyone in', function () {
    $provider = Mockery::mock(FacebookProvider::class);
    $provider->shouldReceive('user')->andThrow(new \Laravel\Socialite\Two\InvalidStateException);
    Socialite::shouldReceive('driver')->with('facebook')->andReturn($provider);

    $this->get('/auth/facebook/callback')->assertRedirect('/?failed_facebook_login=1');
    $this->assertGuest();
});

// Linking from Settings

test('a signed-in user can link another provider from Settings', function () {
    $user = User::factory()->twitch('42')->create();
    fakeProviderAccount('facebook', providerAccount('fb-7', 'Face Person', null));

    $this->actingAs($user)->get(route('identities.link', 'facebook'))
        ->assertRedirect('https://facebook.example/oauth')
        ->assertSessionHas('identities.linking', 'facebook');

    $this->get('/auth/facebook/callback')
        ->assertRedirect(route('settings'))
        ->assertSessionHas('identity_status', 'Facebook account linked.');

    expect(User::count())->toBe(1)
        ->and($user->fresh()->facebook_id)->toBe('fb-7')
        ->and($user->identities()->count())->toBe(2);
});

test('the callback does nothing for a signed-in user who did not ask to link', function () {
    $user = User::factory()->twitch('42')->create();
    fakeProviderAccount('facebook', providerAccount('fb-7'));

    $this->actingAs($user)->get('/auth/facebook/callback')->assertRedirect('/vote');

    expect(Identity::where('provider', 'facebook')->exists())->toBeFalse();
});

test('linking an account another user owns fails clearly and merges nothing', function () {
    $owner = User::factory()->facebook('fb-7')->create();
    $user = User::factory()->twitch('42')->create();
    fakeProviderAccount('facebook', providerAccount('fb-7'));

    $this->actingAs($user)->get(route('identities.link', 'facebook'));
    $this->get('/auth/facebook/callback')
        ->assertRedirect(route('settings'))
        ->assertSessionHas('identity_error', fn (string $message) => str_contains($message, 'already linked to a different ARE account'));

    expect(Identity::for(IdentityProvider::Facebook, 'fb-7')->sole()->user_id)->toBe($owner->id)
        ->and($user->identities()->count())->toBe(1)
        ->and(User::count())->toBe(2);
});

test('a user cannot link a second account on a provider they already linked', function () {
    $user = User::factory()->twitch('42')->create();

    expect(fn () => Identities::link($user, IdentityProvider::Twitch, providerAccount('43')))
        ->toThrow(IdentityLinkException::class, 'already have a Twitch account linked');
});

test('re-linking an account the user already owns just refreshes it', function () {
    $user = User::factory()->twitch('42')->create();

    $identity = Identities::link($user, IdentityProvider::Twitch, providerAccount('42', 'Renamed'));

    expect($identity->name)->toBe('Renamed')->and($user->identities()->count())->toBe(1);
});

test('YouTube cannot be linked through Socialite until Google sign-in exists', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/linked-accounts/youtube/link')
        ->assertNotFound();
});

test('Settings lists linked accounts and offers the others', function () {
    $user = User::factory()->twitch('42')->create();

    $this->actingAs($user)->get('/settings')
        ->assertOk()
        ->assertSee('Linked accounts')
        ->assertSee('Link Facebook')
        ->assertDontSee('Link Twitch');
});

// Unlinking

test('a user can unlink an identity but not their last one', function () {
    $user = User::factory()->twitch('42')->facebook('fb-7')->create();
    $facebook = $user->identityFor(IdentityProvider::Facebook);
    $twitch = $user->identityFor(IdentityProvider::Twitch);

    $this->actingAs($user);
    Volt::test('settings.linked-accounts')->call('unlink', $facebook->id)->assertHasNoErrors();

    expect($user->identities()->pluck('provider')->all())->toBe([IdentityProvider::Twitch]);

    Volt::test('settings.linked-accounts')->call('unlink', $twitch->id)->assertHasErrors('identity');

    expect($user->identities()->count())->toBe(1);
});

test('a user cannot unlink someone else\'s identity', function () {
    $other = User::factory()->twitch('99')->facebook('fb-9')->create();
    $user = User::factory()->twitch('42')->create();

    $this->actingAs($user);
    expect(fn () => Volt::test('settings.linked-accounts')->call('unlink', $other->identityFor(IdentityProvider::Facebook)->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($other->identities()->count())->toBe(2);
});

test('a banned user cannot shed the identity the ban came through', function () {
    $user = User::factory()->twitch('42')->facebook('fb-7')->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);

    expect(fn () => Identities::unlink($user, $user->identityFor(IdentityProvider::Twitch)))
        ->toThrow(IdentityLinkException::class, 'banned');

    expect($user->identities()->count())->toBe(2);
});

// One person, one vote

test('a user with both a Twitch and a YouTube identity gets one vote', function () {
    $person = User::factory()->twitch('42')->youtube('UC-yt-1')->create();
    $question = Question::factory()->for(User::factory())->create();

    // However this person arrives, they resolve to the same user.
    $viaTwitch = Identities::findUser(IdentityProvider::Twitch, '42');
    $viaYouTube = Identities::findUser(IdentityProvider::YouTube, 'UC-yt-1');
    expect($viaTwitch->is($person))->toBeTrue()->and($viaYouTube->is($person))->toBeTrue();

    voteAs($viaTwitch, $question);
    voteAs($viaYouTube, $question);

    expect($question->voteCount())->toBe(1)
        ->and(DB::table('question_votes')->where('question_id', $question->id)->count())->toBe(1);
});

test('linking a YouTube identity to a Twitch user does not create a second voter', function () {
    $user = User::factory()->twitch('42')->create();
    $question = Question::factory()->for(User::factory())->create();
    voteAs($user, $question);

    Identities::link($user, IdentityProvider::YouTube, providerAccount('UC-yt-1', 'Tuber'));
    voteAs(Identities::findUser(IdentityProvider::YouTube, 'UC-yt-1'), $question);

    expect(User::count())->toBe(2)
        ->and($user->identities()->count())->toBe(2)
        ->and($question->voteCount())->toBe(1);
});

test('question limits count per user across identities', function () {
    $user = User::factory()->twitch('42')->facebook('fb-7')->create();
    UserTwitchSubscription::create(['user_id' => $user->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier1]);
    Topic::set('Songs about kale');
    Question::factory()->count(6)->for($user)->create();

    expect(Identities::findUser(IdentityProvider::Facebook, 'fb-7')->canSubmitQuestion())->toBeFalse();
});

// Bans

test('a Twitch ban blocks the linked YouTube identity', function () {
    User::factory()->twitch('42')->youtube('UC-yt-1')->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);

    $viaYouTube = Identities::findUser(IdentityProvider::YouTube, 'UC-yt-1');

    expect($viaYouTube->isBanned())->toBeTrue()
        ->and($viaYouTube->canSubmitQuestion())->toBeFalse();
});

test('a Twitch ban turns the person away at Facebook sign-in', function () {
    User::factory()->twitch('42')->facebook('fb-7')->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
    fakeProviderAccount('facebook', providerAccount('fb-7'));

    $this->get('/auth/facebook/callback')->assertRedirect('/?banned=1');
    $this->assertGuest();
});

test('linking a banned Twitch account bans the user it is linked to', function () {
    $user = User::factory()->facebook('fb-7')->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
    expect($user->isBanned())->toBeFalse();

    Identities::link($user, IdentityProvider::Twitch, providerAccount('42'));

    expect($user->fresh()->isBanned())->toBeTrue();
});

test('a local ban follows the user to every linked identity', function () {
    $user = User::factory()->twitch('42')->facebook('fb-7')->create();
    $mod = User::factory()->twitch('77')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);
    \App\Moderation::ban($mod, $user, null, 'spam');
    fakeProviderAccount('facebook', providerAccount('fb-7'));

    expect(Identities::findUser(IdentityProvider::Facebook, 'fb-7')->isLocallyBanned())->toBeTrue();
    $this->get('/auth/facebook/callback')->assertRedirect('/?banned=1');
    $this->assertGuest();
});

// Moderator view

test('moderators see the linked identities of a question\'s author; viewers do not', function () {
    $author = User::factory()->twitch('42')->youtube('UC-yt-1')->create();
    $author->identities()->update(['name' => 'sockpuppet']);
    Question::factory()->for($author)->create();
    $question = Question::getSortedQuestions()->sole();
    $mod = User::factory()->twitch('77')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);

    $this->actingAs($mod);
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->assertSee('Twitch: sockpuppet (42)')
        ->assertSee('YouTube: sockpuppet (UC-yt-1)');

    $this->actingAs(User::factory()->create());
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->assertDontSee('UC-yt-1');
});

test('identity lists are eager-loaded with the queue', function () {
    Question::factory()->count(3)->for(User::factory()->twitch('42'))->create();

    $questions = Question::getSortedQuestions();

    expect($questions->every(fn ($q) => $q->user->relationLoaded('identities')))->toBeTrue();
});
