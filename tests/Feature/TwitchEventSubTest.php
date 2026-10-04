<?php

use App\Models\TwitchBan;
use App\Models\TwitchModerator;

function eventsub(array $payload, string $type = 'notification', ?string $id = null, ?string $timestamp = null, ?string $secret = null)
{
    $id ??= (string) Str::uuid();
    $timestamp ??= now()->toIso8601ZuluString();
    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $id.$timestamp.$body, $secret ?? config('services.twitch.eventsub_secret'));

    return test()->call('POST', '/twitch/eventsub', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Twitch-Eventsub-Message-Id' => $id,
        'HTTP_Twitch-Eventsub-Message-Timestamp' => $timestamp,
        'HTTP_Twitch-Eventsub-Message-Signature' => $signature,
        'HTTP_Twitch-Eventsub-Message-Type' => $type,
    ], $body);
}

function notification(string $type, array $event): array
{
    return ['subscription' => ['type' => $type], 'event' => $event];
}

test('it answers the verification challenge', function () {
    eventsub(['challenge' => 'pogchamp-kappa-360noscope'], 'webhook_callback_verification')
        ->assertOk()
        ->assertSeeText('pogchamp-kappa-360noscope');
});

test('it rejects a bad signature', function () {
    eventsub(notification('channel.ban', ['broadcaster_user_id' => '1000', 'user_id' => '42']), secret: 'wrong')
        ->assertForbidden();

    expect(TwitchBan::count())->toBe(0);
});

test('it rejects a stale message', function () {
    eventsub(notification('channel.ban', ['broadcaster_user_id' => '1000', 'user_id' => '42']), timestamp: now()->subMinutes(11)->toIso8601ZuluString())
        ->assertForbidden();
});

test('it rejects everything when no secret is configured', function () {
    config(['services.twitch.eventsub_secret' => null]);

    eventsub(['challenge' => 'x'], 'webhook_callback_verification', secret: '')->assertForbidden();
});

test('it records permanent bans and timeouts, and removes them on unban', function () {
    eventsub(notification('channel.ban', ['broadcaster_user_id' => '1000', 'user_id' => '42', 'is_permanent' => true]))->assertNoContent();
    $ends = now()->addMinutes(10)->startOfSecond();
    eventsub(notification('channel.ban', ['broadcaster_user_id' => '2000', 'user_id' => '43', 'is_permanent' => false, 'ends_at' => $ends->toIso8601ZuluString()]))->assertNoContent();

    expect(TwitchBan::where('twitch_user_id', '42')->first()->ends_at)->toBeNull()
        ->and(TwitchBan::where('twitch_user_id', '43')->first()->ends_at->equalTo($ends))->toBeTrue();

    eventsub(notification('channel.unban', ['broadcaster_user_id' => '1000', 'user_id' => '42']))->assertNoContent();

    expect(TwitchBan::pluck('twitch_user_id')->all())->toBe(['43']);
});

test('it tracks moderators being added and removed', function () {
    eventsub(notification('channel.moderator.add', ['broadcaster_user_id' => '1000', 'user_id' => '77']));
    expect(TwitchModerator::where('twitch_user_id', '77')->exists())->toBeTrue();

    eventsub(notification('channel.moderator.remove', ['broadcaster_user_id' => '1000', 'user_id' => '77']));
    expect(TwitchModerator::count())->toBe(0);
});

test('it ignores channels this app does not serve', function () {
    eventsub(notification('channel.moderator.add', ['broadcaster_user_id' => '9999', 'user_id' => '77']))->assertNoContent();

    expect(TwitchModerator::count())->toBe(0);
});

test('a redelivered message is handled once', function () {
    $id = (string) Str::uuid();
    eventsub(notification('channel.moderator.add', ['broadcaster_user_id' => '1000', 'user_id' => '77']), id: $id);
    TwitchModerator::query()->delete();

    eventsub(notification('channel.moderator.add', ['broadcaster_user_id' => '1000', 'user_id' => '77']), id: $id)->assertNoContent();

    expect(TwitchModerator::count())->toBe(0);
});
