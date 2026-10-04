<?php

use App\Exceptions\BannedAccountException;
use App\Exceptions\IdentityLinkException;
use App\Http\Middleware\EnsureNotBanned;
use App\Identities;
use App\IdentityProvider;
use App\Models\Identity;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserBan;
use App\Moderation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\TwitchProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Livewire\Volt\Volt;

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000', 'services.twitch.broadcaster_ids' => []]);
});

function evasionModerator(string $twitchId = '77'): User
{
    $mod = User::factory()->twitch($twitchId)->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $twitchId]);

    return $mod;
}

function facebookAccount(string $id): SocialiteUser
{
    return (new SocialiteUser)->map(['id' => $id, 'name' => 'Facebook Troll', 'nickname' => null, 'email' => null, 'avatar' => null]);
}

function facebookCallbackReturns(string $id): void
{
    $provider = Mockery::mock(FacebookProvider::class);
    $provider->shouldReceive('user')->andReturn(facebookAccount($id));
    Socialite::shouldReceive('driver')->with('facebook')->andReturn($provider);
}

// Andras's repro (#41), on the identity model.

test('a locally banned user cannot erase their ban by deleting their account', function () {
    $target = User::factory()->facebook('fb-9')->create(['name' => 'Facebook Troll']);
    Moderation::ban(evasionModerator(), $target, null, 'spam');

    // Even if the account is deleted, the ban stays with the account it covered.
    $target->delete();
    expect(UserBan::inEffect()->forAccount(IdentityProvider::Facebook, 'fb-9')->exists())->toBeTrue();

    // What the Facebook callback does on the next login.
    facebookCallbackReturns('fb-9');
    $this->get('/auth/facebook/callback')->assertRedirect('/?banned=1');

    $this->assertGuest();
    expect(Identity::for(IdentityProvider::Facebook, 'fb-9')->exists())->toBeFalse()
        ->and(User::where('name', 'Facebook Troll')->exists())->toBeFalse();
});

test('a banned user cannot delete their account from Settings', function () {
    $target = User::factory()->facebook('fb-9')->create();
    Moderation::ban(evasionModerator(), $target, null, 'spam');

    $this->actingAs($target);
    Volt::test('settings.delete-user-form')->call('deleteUser')->assertForbidden();

    expect(User::whereKey($target->id)->exists())->toBeTrue();
});

test('an unbanned user can still delete their account', function () {
    $user = User::factory()->facebook('fb-1')->create();

    $this->actingAs($user);
    Volt::test('settings.delete-user-form')->call('deleteUser')->assertRedirect('/');

    expect(User::whereKey($user->id)->exists())->toBeFalse();
});

test('a banned Twitch account is refused before any user, Helix call or subscription retry', function () {
    Queue::fake();
    Http::fake();
    $target = User::factory()->twitch('42')->create();
    Moderation::ban(evasionModerator(), $target, null);
    $target->delete();

    $account = (new SocialiteUser)->map(['id' => '42', 'name' => 'Troll', 'nickname' => 'Troll', 'email' => null, 'avatar' => null])
        ->setToken('t')->setRefreshToken('r')->setExpiresIn(3600);
    $provider = Mockery::mock(TwitchProvider::class);
    $provider->shouldReceive('user')->andReturn($account);
    Socialite::shouldReceive('driver')->with('twitch')->andReturn($provider);

    $this->get('/twitch/auth')->assertRedirect('/?banned=1');

    $this->assertGuest();
    expect(Identity::for(IdentityProvider::Twitch, '42')->exists())->toBeFalse();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('signing in with a banned account that no user holds is refused before a user is created', function () {
    $target = User::factory()->facebook('fb-9')->create();
    Moderation::ban(evasionModerator(), $target, 60);
    $target->delete();

    expect(fn () => Identities::signIn(IdentityProvider::Facebook, facebookAccount('fb-9')))
        ->toThrow(BannedAccountException::class);
    expect(User::count())->toBe(1);
});

test('an expired or lifted ban no longer blocks the account it covered', function () {
    $mod = evasionModerator();
    $target = User::factory()->facebook('fb-9')->create();
    $ban = Moderation::ban($mod, $target, 10);
    $target->delete();

    $this->travel(11)->minutes();
    expect(Identities::signIn(IdentityProvider::Facebook, facebookAccount('fb-9'))->facebook_id)->toBe('fb-9');

    Identity::query()->where('provider', 'facebook')->delete();
    $ban->update(['ends_at' => null, 'lifted_at' => now()]);
    expect(Identities::signIn(IdentityProvider::Facebook, facebookAccount('fb-9'))->facebook_id)->toBe('fb-9');
});

test('a ban copies every identity the user holds', function () {
    $target = User::factory()->twitch('42')->facebook('fb-9')->youtube('UC-1')->create();

    $ban = Moderation::ban(evasionModerator(), $target, null);

    expect($ban->identities()->orderBy('provider')->get()->map(fn ($i) => $i->provider->value.':'.$i->provider_user_id)->all())
        ->toBe(['facebook:fb-9', 'twitch:42', 'youtube:UC-1']);
});

test('deleting a banned user keeps the ban row and nulls its user', function () {
    $target = User::factory()->facebook('fb-9')->create();
    $ban = Moderation::ban(evasionModerator(), $target, null);

    $target->delete();

    expect($ban->fresh())->not->toBeNull()
        ->and($ban->fresh()->user_id)->toBeNull()
        ->and($ban->identities()->count())->toBe(1);
});

test('a clean user who links a banned account is banned by it, and unban lifts it', function () {
    $mod = evasionModerator();
    $target = User::factory()->facebook('fb-9')->create();
    Moderation::ban($mod, $target, null);
    $target->delete();

    $other = User::factory()->twitch('42')->create();
    expect($other->isBanned())->toBeFalse();

    Identities::link($other, IdentityProvider::Facebook, facebookAccount('fb-9'));
    expect($other->isLocallyBanned())->toBeTrue();

    expect(Moderation::unban($mod, $other))->toBe(1)
        ->and($other->isLocallyBanned())->toBeFalse();
});

test('a banned user cannot link another account', function () {
    $target = User::factory()->twitch('42')->create();
    Moderation::ban(evasionModerator(), $target, null);

    expect(fn () => Identities::link($target, IdentityProvider::Facebook, facebookAccount('fb-new')))
        ->toThrow(IdentityLinkException::class, 'cannot link accounts while you are banned');
    expect(Identity::for(IdentityProvider::Facebook, 'fb-new')->exists())->toBeFalse();
});

// Moderation page

test('a ban on a deleted account is listed with its accounts and can be lifted', function () {
    $mod = evasionModerator();
    $target = User::factory()->facebook('fb-9')->create();
    $ban = Moderation::ban($mod, $target, null, 'spam');
    $target->delete();

    $this->actingAs($mod)->get('/moderation')->assertOk()
        ->assertSee('Deleted account')
        ->assertSee('Facebook fb-9');

    Volt::test('moderation')->call('liftBan', $ban->id)->assertOk();

    expect($ban->fresh()->lifted_at)->not->toBeNull()
        ->and(UserBan::inEffect()->forAccount(IdentityProvider::Facebook, 'fb-9')->exists())->toBeFalse();
});

test('only the broadcaster can lift a deleted account\'s ban that covers a Twitch moderator', function () {
    $broadcaster = User::factory()->twitch('1000')->create();
    $rogue = evasionModerator('88');
    $ban = Moderation::ban($broadcaster, $rogue, null);
    $rogue->delete();

    $this->actingAs(evasionModerator());
    Volt::test('moderation')->call('liftBan', $ban->id)->assertForbidden();
    expect($ban->fresh()->lifted_at)->toBeNull();

    $this->actingAs($broadcaster);
    Volt::test('moderation')->call('liftBan', $ban->id)->assertOk();
    expect($ban->fresh()->lifted_at)->not->toBeNull();
});

// Livewire persistent middleware

test('the ban check is registered as Livewire persistent middleware', function () {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(EnsureNotBanned::class);
});

test('a page opened before a ban cannot act after it: /livewire/update re-runs the ban check', function () {
    $user = User::factory()->facebook('fb-9')->create();
    $html = $this->actingAs($user)->get('/settings')->assertOk()->getContent();

    // The delete-account component's snapshot, as the browser holds it.
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $json) => str_contains($json, 'settings.delete-user-form'));
    expect($snapshot)->not->toBeNull();

    Moderation::ban(evasionModerator(), $user, null);

    $response = $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'deleteUser', 'params' => []]],
        ]],
    ]);

    // EnsureNotBanned answered (sign out and redirect), not the action's own 403.
    $response->assertRedirect('/?banned=1');
    $this->assertGuest();
    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

// Migration

test('the migration copies existing bans\' identities and stops the cascade, and rolls back', function () {
    $migration = require database_path('migrations/2026_10_04_223126_key_user_bans_to_identities.php');
    $migration->down();
    expect(Schema::hasTable('user_ban_identities'))->toBeFalse();

    // A ban in the pre-migration shape.
    $mod = evasionModerator();
    $target = User::factory()->twitch('42')->facebook('fb-9')->create();
    $banId = DB::table('user_bans')->insertGetId(['user_id' => $target->id, 'moderator_id' => $mod->id, 'reason' => 'old', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    $ban = UserBan::findOrFail($banId);
    expect($ban->identities()->orderBy('provider')->pluck('provider_user_id')->all())->toBe(['fb-9', '42']);

    $target->delete();
    expect($ban->fresh()->user_id)->toBeNull()
        ->and(UserBan::inEffect()->forAccount(IdentityProvider::Twitch, '42')->exists())->toBeTrue();

    // Down: the orphaned ban goes (the old cascade would have taken it) and the cascade returns.
    $migration->down();
    expect(DB::table('user_bans')->where('id', $banId)->exists())->toBeFalse();

    $other = User::factory()->create();
    DB::table('user_bans')->insert(['user_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
    $other->delete();
    expect(DB::table('user_bans')->count())->toBe(0);

    $migration->up();
    expect(Schema::hasTable('user_ban_identities'))->toBeTrue();
});
