<?php

use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('the dashboard runs outside local, so the viewHorizon gate decides', function () {
    expect(app()->environment('local'))->toBeFalse();
});

test('a moderator can open the Horizon dashboard', function () {
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    $this->actingAs($mod)->get('/horizon')->assertOk();
});

test('the broadcaster can open the Horizon dashboard', function () {
    $broadcaster = User::factory()->create(['twitch_id' => '1000']);

    $this->actingAs($broadcaster)->get('/horizon')->assertOk();
});

test('a viewer gets 403 from the Horizon dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});

test('a guest gets 403 from the Horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('a banned moderator gets 403 from the Horizon dashboard', function () {
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);
    $mod->localBans()->create(['moderator_id' => $mod->id]);

    $this->actingAs($mod)->get('/horizon')->assertForbidden();
});

test('horizon:snapshot is scheduled every five minutes', function () {
    $snapshot = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'horizon:snapshot'));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->expression)->toBe('*/5 * * * *');
});

test('every environment runs a broadcasts supervisor that never scales to zero', function (string $environment) {
    $supervisor = array_replace(
        config('horizon.defaults.supervisor-broadcasts'),
        config("horizon.environments.{$environment}.supervisor-broadcasts"),
    );

    expect($supervisor['queue'])->toBe(['broadcasts'])
        ->and($supervisor['minProcesses'])->toBeGreaterThanOrEqual(1);
})->with(['production', 'local']);

test('every supervisor times out before the redis connection retries the job', function (string $environment) {
    $retryAfter = config('queue.connections.redis.retry_after');

    foreach (config("horizon.environments.{$environment}") as $name => $overrides) {
        $supervisor = array_replace(config("horizon.defaults.{$name}", []), $overrides);

        expect($supervisor['timeout'])->toBeLessThan($retryAfter - 5, "{$environment}.{$name}");
    }
})->with(['production', 'local']);
