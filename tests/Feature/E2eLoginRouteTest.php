<?php

use App\Models\OverlayToken;
use App\Models\User;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\Process;

// The browser suite's sign-in route (#155) must exist only when APP_ENV=testing.

test('in testing, the e2e login route signs in a seeded user and goes to a same-site path', function () {
    $user = User::factory()->create();

    $this->get("/_e2e/login/{$user->id}?to=/about")->assertRedirect('/about');

    $this->assertAuthenticatedAs($user);
});

test('the e2e login route never redirects off-site', function (string $to) {
    $user = User::factory()->create();

    $this->get("/_e2e/login/{$user->id}?to=".urlencode($to))->assertRedirect('/vote');
})->with(['//evil.example/', 'https://evil.example/', 'javascript:alert(1)']);

test('outside testing, the e2e login route refuses with a 404 even if it is registered', function (string $environment) {
    $user = User::factory()->create();
    app()->detectEnvironment(fn () => $environment);

    try {
        $this->get("/_e2e/login/{$user->id}")->assertNotFound();
        $this->assertGuest();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
})->with(['production', 'local', 'staging']);

test('the e2e seeder refuses to run outside testing, because it mints live overlay tokens', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => (new E2eSeeder)->run())->toThrow(RuntimeException::class, 'APP_ENV=testing');
        expect(OverlayToken::count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

test('outside testing, the e2e login route is not registered at all', function () {
    $routes = fn (string $environment) => Process::env(['APP_ENV' => $environment])
        ->run([PHP_BINARY, base_path('artisan'), 'route:list', '--json'])
        ->output();

    expect($routes('production'))->toContain('"uri":"up"')->not->toContain('_e2e')
        ->and($routes('local'))->not->toContain('_e2e')
        ->and($routes('testing'))->toContain('_e2e\/login\/{user}');
});
