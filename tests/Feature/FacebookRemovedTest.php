<?php

use App\IdentityProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Facebook sign-in was dropped: ARE signs in with Twitch, and links YouTube
// through chat.

test('Facebook is not an identity provider', function () {
    expect(IdentityProvider::tryFrom('facebook'))->toBeNull()
        ->and(config('services.facebook'))->toBeNull()
        ->and(config('bus.platform_latency_seconds'))->not->toHaveKey('facebook');
});

test('the Facebook sign-in and callback routes are gone', function () {
    $this->get('/login/facebook')->assertNotFound();
    $this->get('/auth/facebook/callback')->assertNotFound();
    $this->actingAs(User::factory()->twitch('42')->create())
        ->get('/settings/linked-accounts/facebook/link')->assertNotFound();
});

test('the welcome page offers only Twitch sign-in', function () {
    $this->get('/')->assertOk()
        ->assertSee('Login with Twitch')
        ->assertDontSee('Facebook');
});

test('the migration removes leftover Facebook rows', function () {
    $user = User::factory()->twitch('42')->create();
    DB::table('identities')->insert(['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'fb-7', 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_05_120000_remove_facebook_rows.php'))->up();

    expect(DB::table('identities')->where('provider', 'facebook')->exists())->toBeFalse()
        ->and($user->fresh()->identities()->count())->toBe(1);
});
