<?php

use App\Models\BroadcasterToken;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\YouTubeChannelToken;
use App\Twitch;
use App\YouTube\YouTubeApi;
use Livewire\Volt\Volt;

// #194: broadcasters had no link to connect their channel anywhere in the app.

beforeEach(function () {
    config([
        'services.twitch.broadcaster_id' => '1000',
        'services.twitch.broadcaster_ids' => ['1426542672'],
        'services.youtube.channel_ids' => [],
    ]);
});

function connectChannel(string $id, array $scopes): void
{
    BroadcasterToken::create([
        'broadcaster_id' => $id, 'access_token' => 'a', 'refresh_token' => 'r',
        'expires_at' => now()->addHour(), 'scopes' => $scopes,
    ]);
}

test('a broadcaster who has not connected sees Channel connection with a Connect button', function () {
    $this->actingAs(User::factory()->twitch('1426542672')->create());

    $this->get('/settings')->assertOk()
        ->assertSee('Channel connection')
        ->assertSee('data-twitch-channel="1426542672"', false)
        ->assertSee('data-state="not-connected"', false)
        ->assertSee('Not connected')
        ->assertSee(route('twitch.broadcaster.connect'), false);
});

test('only the broadcaster\'s own channel is listed', function () {
    $this->actingAs(User::factory()->twitch('1426542672')->create());

    $this->get('/settings')->assertOk()
        ->assertSee('data-twitch-channel="1426542672"', false)
        ->assertDontSee('data-twitch-channel="1000"', false);
});

test('a connection missing scopes asks for a reconnect and lists them', function () {
    connectChannel('1426542672', array_values(array_diff(Twitch::BROADCASTER_SCOPES, ['user:write:chat', 'channel:manage:clips'])));
    $this->actingAs(User::factory()->twitch('1426542672')->create());

    $this->get('/settings')->assertOk()
        ->assertSee('data-state="missing-scopes"', false)
        ->assertSee('Reconnect needed')
        ->assertSee('user:write:chat')
        ->assertSee('channel:manage:clips')
        ->assertDontSee('moderation:read')
        ->assertSee('Reconnect');
});

test('a connection with every scope shows Connected', function () {
    connectChannel('1426542672', Twitch::BROADCASTER_SCOPES);
    $this->actingAs(User::factory()->twitch('1426542672')->create());

    $this->get('/settings')->assertOk()
        ->assertSee('data-state="connected"', false)
        ->assertDontSee('Reconnect needed')
        ->assertDontSee('Not connected');
});

test('Settings and Readiness report the same missing scopes', function () {
    connectChannel('1426542672', ['moderation:read']);

    expect(BroadcasterToken::sole()->missingScopes())
        ->toBe(array_values(array_diff(Twitch::BROADCASTER_SCOPES, ['moderation:read'])));
});

test('YouTube connect shows when YouTube channels are configured, with each channel\'s state', function () {
    config(['services.youtube.channel_ids' => ['UC-show', 'UC-second']]);
    YouTubeChannelToken::create([
        'channel_id' => 'UC-show', 'channel_title' => 'The Show', 'access_token' => 'a', 'refresh_token' => 'r',
        'expires_at' => now()->addHour(), 'scopes' => [YouTubeApi::POST_SCOPE],
    ]);
    $this->actingAs(User::factory()->twitch('1426542672')->create());

    $this->get('/settings')->assertOk()
        ->assertSee('YouTube channel The Show (UC-show)')
        ->assertSee('data-youtube-channel="UC-second"', false)
        ->assertSee('yt-analytics.readonly')
        ->assertSee(route('youtube.broadcaster.connect'), false);
});

test('without configured YouTube channels there is no YouTube connect', function () {
    $this->actingAs(User::factory()->twitch('1426542672')->create());

    $this->get('/settings')->assertOk()->assertDontSee(route('youtube.broadcaster.connect'), false);
});

test('viewers and moderators do not see Channel connection', function () {
    $this->actingAs(User::factory()->twitch('42')->create());
    $this->get('/settings')->assertOk()
        ->assertDontSee('Channel connection')
        ->assertDontSee(route('twitch.broadcaster.connect'), false);

    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);
    $this->actingAs(User::factory()->twitch('77')->create());
    $this->get('/settings')->assertOk()
        ->assertDontSee('Channel connection')
        ->assertDontSee(route('twitch.broadcaster.connect'), false);
});

test('the component refuses a non-broadcaster reached directly', function () {
    $this->actingAs(User::factory()->twitch('42')->create());

    Volt::test('settings.channel-connection')->assertForbidden();
});
