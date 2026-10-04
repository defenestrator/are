<?php

use App\Models\BroadcasterToken;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Twitch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function connectBroadcaster(string $id = '1000', ?\DateTimeInterface $expiresAt = null): BroadcasterToken
{
    return BroadcasterToken::create([
        'broadcaster_id' => $id,
        'access_token' => 'access-'.$id,
        'refresh_token' => 'refresh-'.$id,
        'expires_at' => $expiresAt ?? now()->addHours(4),
        'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);
}

test('tokens are stored encrypted', function () {
    connectBroadcaster();

    $raw = DB::table('broadcaster_tokens')->first();
    expect($raw->access_token)->not->toContain('access-1000')
        ->and($raw->refresh_token)->not->toContain('refresh-1000')
        ->and(BroadcasterToken::first()->access_token)->toBe('access-1000');
});

test('an expired broadcaster token is refreshed before use', function () {
    connectBroadcaster(expiresAt: now()->subMinute());
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'rotated', 'expires_in' => 14400, 'scope' => Twitch::BROADCASTER_SCOPES]),
    ]);

    expect(Twitch::broadcasterAccessToken('1000'))->toBe('fresh')
        ->and(BroadcasterToken::first()->refresh_token)->toBe('rotated');

    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-1000');
});

test('twitch:title patches the channel through Helix', function () {
    connectBroadcaster();
    Http::fake(['api.twitch.tv/helix/channels*' => Http::response(null, 204)]);

    $this->artisan('twitch:title', ['title' => 'Building "Orkestera"; live & loud'])->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && str_contains($r->url(), 'broadcaster_id=1000')
        && $r['title'] === 'Building "Orkestera"; live & loud'
        && $r->hasHeader('Authorization', 'Bearer access-1000'));
});

test('twitch:title refuses channels this app does not serve', function () {
    Http::fake();

    $this->artisan('twitch:title', ['title' => 'nope', '--broadcaster' => '9999'])->assertFailed();

    Http::assertNothingSent();
});

test('moderation sync replaces bans and moderators, following pagination', function () {
    connectBroadcaster();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => 'stale']);
    TwitchBan::create(['broadcaster_id' => '2000', 'twitch_user_id' => 'other-channel']);

    Http::fake([
        'api.twitch.tv/helix/moderation/banned*' => Http::sequence()
            ->push(['data' => [['user_id' => '42', 'expires_at' => '']], 'pagination' => ['cursor' => 'next']])
            ->push(['data' => [['user_id' => '43', 'expires_at' => now()->addHour()->toIso8601ZuluString()]], 'pagination' => []]),
        'api.twitch.tv/helix/moderation/moderators*' => Http::response(['data' => [['user_id' => '77']], 'pagination' => []]),
    ]);

    $this->artisan('twitch:sync-moderation')->assertSuccessful();

    expect(TwitchBan::where('broadcaster_id', '1000')->pluck('twitch_user_id')->sort()->values()->all())->toBe(['42', '43'])
        ->and(TwitchBan::where('broadcaster_id', '2000')->exists())->toBeTrue()
        ->and(TwitchModerator::pluck('twitch_user_id')->all())->toBe(['77']);
});

function fakeTwitchUser(string $id): void
{
    $twitchUser = (new SocialiteUser)->map(['id' => $id, 'name' => 'Broadcaster'])
        ->setToken('granted-access')
        ->setRefreshToken('granted-refresh')
        ->setExpiresIn(14400)
        ->setApprovedScopes(Twitch::BROADCASTER_SCOPES);

    $provider = Mockery::mock(\Laravel\Socialite\Two\TwitchProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($twitchUser);
    Socialite::shouldReceive('driver')->with('twitch')->andReturn($provider);
}

test('a broadcaster can connect their channel', function () {
    Http::fake(['api.twitch.tv/*' => Http::response(['data' => [], 'pagination' => []])]);
    fakeTwitchUser('2000');

    $this->actingAs(User::factory()->create(['twitch_id' => '2000']))
        ->get('/twitch/broadcaster/callback')
        ->assertRedirect('/vote');

    expect(BroadcasterToken::where('broadcaster_id', '2000')->first()->access_token)->toBe('granted-access');
});

test('a broadcaster cannot attach a different Twitch account', function () {
    fakeTwitchUser('1000');

    $this->actingAs(User::factory()->create(['twitch_id' => '2000']))
        ->get('/twitch/broadcaster/callback')
        ->assertForbidden();

    expect(BroadcasterToken::count())->toBe(0);
});

test('viewers cannot start the broadcaster connection', function () {
    $this->actingAs(User::factory()->create())
        ->get('/twitch/broadcaster/connect')
        ->assertForbidden();
});

test('a banned user is turned away at Twitch login', function () {
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
    $twitchUser = (new SocialiteUser)->map(['id' => '42', 'name' => 'Troll', 'avatar' => 'https://example.com/a.png'])->setToken('t');
    $provider = Mockery::mock(\Laravel\Socialite\Two\TwitchProvider::class);
    $provider->shouldReceive('user')->andReturn($twitchUser);
    Socialite::shouldReceive('driver')->with('twitch')->andReturn($provider);
    Http::fake(['api.twitch.tv/*' => Http::response(['data' => []])]);

    $this->get('/twitch/auth')->assertRedirect('/?banned=1');
    $this->assertGuest();
});
