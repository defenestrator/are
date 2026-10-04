<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\IdentityProvider;
use App\Jobs\EventSub\HandleChatMessage;
use App\Models\ChatCommandRun;
use App\Models\ShortLink;
use App\Models\StreamSession;
use App\Models\TwitchBan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-04 20:00:00'));
});

/** Run a chat message through the registry and return its result. */
function linkCommand(
    string $text,
    string $chatterId = '4145994',
    string $channelId = '1000',
    IdentityProvider $provider = IdentityProvider::Twitch,
): ?ChatCommandResult {
    return app(ChatCommandRegistry::class)->run($provider, $channelId, $chatterId, 'viewer32', (string) Str::uuid(), $text);
}

function liveStream(string $streamId = '40123456789', string $broadcasterId = '1000'): StreamSession
{
    return StreamSession::factory()->create([
        'broadcaster_id' => $broadcasterId,
        'twitch_stream_id' => $streamId,
        'started_at' => now()->subHour(),
    ]);
}

test('!orkestera replies with a tracked link for this platform and stream, even to an unlinked chatter', function () {
    liveStream();

    $result = linkCommand('!orkestera');
    $link = ShortLink::sole();

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toBe('Orkestera is the agentic workflow suite EDOS builds: '.$link->url())
        ->and($link->destination)->toBe('/about#orkestera')
        ->and($link->utm())->toBe([
            'utm_source' => 'twitch',
            'utm_medium' => 'stream',
            'utm_campaign' => '2026-10-04-stream-40123456789',
            'utm_content' => 'chat',
        ]);
});

test('!edos replies with a tracked link to the enquiry form', function () {
    liveStream();

    $result = linkCommand('!EDOS');
    $link = ShortLink::sole();

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toBe('Want EDOS to build something like this with your team? Tell us about it: '.$link->url())
        ->and($link->destination)->toBe('/about#work-with-us')
        ->and($link->utm_campaign)->toBe('2026-10-04-stream-40123456789');
});

test('the link is stable for the whole stream, so clicks aggregate, and a new stream gets a new link', function () {
    $first = liveStream('111');

    $a = linkCommand('!edos')->reply;
    $this->travel(5)->minutes();
    $b = linkCommand('!edos', chatterId: '999')->reply;

    expect($a)->toBe($b)->and(ShortLink::count())->toBe(1);

    $first->update(['ended_at' => now()]);
    liveStream('222');
    $this->travel(5)->minutes();

    $c = linkCommand('!edos')->reply;

    expect($c)->not->toBe($a)
        ->and(ShortLink::count())->toBe(2)
        ->and(ShortLink::latest('id')->first()->utm_campaign)->toBe('2026-10-04-stream-222');
});

test('each command answers at most once per 30 seconds per channel, whoever asks', function () {
    liveStream();
    liveStream('333', broadcasterId: '2000');

    expect(linkCommand('!orkestera', chatterId: '1')->status)->toBe(ChatCommandStatus::Done);

    $limited = linkCommand('!orkestera', chatterId: '2');
    expect($limited->status)->toBe(ChatCommandStatus::RateLimited)
        ->and($limited->reply)->toBe('');

    // Another command, and another channel, have their own cooldowns.
    expect(linkCommand('!edos', chatterId: '2')->status)->toBe(ChatCommandStatus::Done)
        ->and(linkCommand('!orkestera', chatterId: '2', channelId: '2000')->status)->toBe(ChatCommandStatus::Done);

    $this->travel(31)->seconds();

    expect(linkCommand('!orkestera', chatterId: '3')->status)->toBe(ChatCommandStatus::Done);
});

test('the cooldown is configurable', function () {
    config(['are.chat_links.cooldown_seconds' => 120]);
    liveStream();

    linkCommand('!orkestera', chatterId: '1');
    $this->travel(61)->seconds();

    expect(linkCommand('!orkestera', chatterId: '2')->status)->toBe(ChatCommandStatus::RateLimited);
});

test('YouTube chat during a Twitch stream is tagged youtube, with the open stream as campaign', function () {
    liveStream();

    linkCommand('!edos', chatterId: 'UCviewer', channelId: 'UCedos', provider: IdentityProvider::YouTube);

    expect(ShortLink::sole()->utm())->toMatchArray([
        'utm_source' => 'youtube',
        'utm_campaign' => '2026-10-04-stream-40123456789',
    ]);
});

test('a Twitch channel prefers its own open stream over another channel\'s', function () {
    liveStream('555', broadcasterId: '2000');
    liveStream('444', broadcasterId: '1000');

    linkCommand('!edos', channelId: '2000');

    expect(ShortLink::sole()->utm_campaign)->toBe('2026-10-04-stream-555');
});

test('with no open stream the campaign is the date, marked offline; ended streams are ignored', function () {
    StreamSession::factory()->ended()->create(['twitch_stream_id' => '666']);

    linkCommand('!orkestera');

    expect(ShortLink::sole()->utm_campaign)->toBe('2026-10-04-offline');
});

test('destinations come from config, including absolute URLs', function () {
    config(['are.chat_links.orkestera.destination' => 'https://github.com/EDOS-Engineering/Orkestera']);
    liveStream();

    linkCommand('!orkestera');

    expect(ShortLink::sole()->destinationUrl())
        ->toStartWith('https://github.com/EDOS-Engineering/Orkestera?utm_source=twitch&utm_medium=stream&utm_campaign=2026-10-04-stream-40123456789');
});

test('banned linked chatters still get nothing (the registry rule applies)', function () {
    liveStream();
    $viewer = User::factory()->twitch('4145994')->create();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => $viewer->twitch_id]);

    expect(linkCommand('!orkestera')->status)->toBe(ChatCommandStatus::Banned)
        ->and(ShortLink::count())->toBe(0);
});

test('a real Twitch chat message runs !edos through the chat job, and clicking the link attributes the stream', function () {
    liveStream();

    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), [
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'chatter_user_id' => '4145994',
        'chatter_user_login' => 'viewer32',
        'chatter_user_name' => 'viewer32',
        'message_id' => 'msg-1',
        'message' => ['text' => '!edos', 'fragments' => []],
        'message_type' => 'text',
        'badges' => [],
    ]))->handle();

    $link = ShortLink::sole();

    expect(ChatCommandRun::where('message_id', 'msg-1')->value('status'))->toBe('done');

    $this->get($link->url())
        ->assertRedirectContains('/about?utm_source=twitch&utm_medium=stream&utm_campaign=2026-10-04-stream-40123456789&utm_content=chat#work-with-us')
        ->assertSessionHas(ShortLink::SESSION_KEY.'.utm_campaign', '2026-10-04-stream-40123456789');
});
