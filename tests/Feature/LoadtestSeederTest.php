<?php

use App\Models\OverlayToken;
use App\Models\User;
use Database\Seeders\LoadtestSeeder;

// The load test's seeder (#176) mints live overlay tokens and test viewers,
// so it must never run outside APP_ENV=testing.

test('the load-test seeder refuses to run outside testing', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    try {
        expect(fn () => (new LoadtestSeeder)->run())->toThrow(RuntimeException::class, 'APP_ENV=testing');
        expect(OverlayToken::count())->toBe(0)
            ->and(User::count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
})->with(['production', 'local', 'staging']);
