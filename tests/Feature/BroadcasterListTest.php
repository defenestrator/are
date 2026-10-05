<?php

use App\Models\User;

// The served channels beyond TWITCH_CHANNEL_ID are committed in
// config/services.php, so adding a broadcaster is a reviewed change.
// TestCase overrides the config, so these read the file itself.

function servedChannelsFromConfig(?string $env): array
{
    $previous = $_ENV['TWITCH_BROADCASTER_IDS'] ?? null;

    if ($env === null) {
        unset($_ENV['TWITCH_BROADCASTER_IDS'], $_SERVER['TWITCH_BROADCASTER_IDS']);
    } else {
        $_ENV['TWITCH_BROADCASTER_IDS'] = $_SERVER['TWITCH_BROADCASTER_IDS'] = $env;
    }

    try {
        return (require config_path('services.php'))['twitch']['broadcaster_ids'];
    } finally {
        if ($previous === null) {
            unset($_ENV['TWITCH_BROADCASTER_IDS'], $_SERVER['TWITCH_BROADCASTER_IDS']);
        } else {
            $_ENV['TWITCH_BROADCASTER_IDS'] = $_SERVER['TWITCH_BROADCASTER_IDS'] = $previous;
        }
    }
}

it('serves dansdumpsterfire without any env setting', function () {
    expect(servedChannelsFromConfig(null))->toBe(['1426542672']);
});

it('adds TWITCH_BROADCASTER_IDS to the committed list, trimmed and without duplicates', function () {
    expect(servedChannelsFromConfig(' 3000, 1426542672,,4000 '))->toBe(['1426542672', '3000', '4000']);
});

it('makes the committed channel a broadcaster', function () {
    config(['services.twitch.broadcaster_ids' => servedChannelsFromConfig(null)]);

    $dan = User::factory()->twitch('1426542672')->create();
    $viewer = User::factory()->twitch('5555')->create();

    expect($dan->isBroadcaster())->toBeTrue()
        ->and($dan->isBroadcasterOf('1426542672'))->toBeTrue()
        ->and($viewer->isBroadcaster())->toBeFalse()
        ->and(User::getBroadcasterIDs())->toBe(['1000', '1426542672']);
});
