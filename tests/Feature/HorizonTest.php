<?php

use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\ProvisioningPlan;

test('the dashboard runs outside local, so the viewHorizon gate decides', function () {
    expect(app()->environment('local'))->toBeFalse();
});

test('a broadcaster of any served channel can open the Horizon dashboard', function (string $twitchId) {
    $broadcaster = User::factory()->twitch($twitchId)->create();

    $this->actingAs($broadcaster)->get('/horizon')->assertOk();
})->with(['primary channel' => '1000', 'extra channel' => '2000']);

test('a moderator gets 403 from the Horizon dashboard, because it exposes job payloads', function () {
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    expect($mod->can('moderate'))->toBeTrue();
    $this->actingAs($mod)->get('/horizon')->assertForbidden();
});

test('a banned broadcaster gets 403 from the Horizon dashboard', function () {
    $broadcaster = User::factory()->twitch('1000')->create();
    $broadcaster->localBans()->create(['moderator_id' => $broadcaster->id]);

    $this->actingAs($broadcaster)->get('/horizon')->assertForbidden();
});

test('a viewer gets 403 from the Horizon dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});

test('a guest gets 403 from the Horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('horizon:snapshot is scheduled every five minutes', function () {
    $snapshot = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'horizon:snapshot'));

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
})->with(['production', 'local', '*']);

test('every supervisor times out before the redis connection retries the job', function (string $environment) {
    $retryAfter = config('queue.connections.redis.retry_after');

    foreach (config("horizon.environments.{$environment}") as $name => $overrides) {
        $supervisor = array_replace(config("horizon.defaults.{$name}", []), $overrides);

        expect($supervisor['timeout'])->toBeLessThan($retryAfter - 5, "{$environment}.{$name}");
    }
})->with(['production', 'local', '*']);

test('Horizon starts both supervisors whatever APP_ENV is', function (string $environment) {
    Event::fake();
    $queues = [];
    $this->mock(HorizonCommandQueue::class)
        ->shouldReceive('push')
        ->andReturnUsing(function ($_, $command, array $options) use (&$queues) {
            $queues[] = $options['queue'];
        });

    ProvisioningPlan::get('test-master')->deploy($environment);

    expect($queues)->toEqualCanonicalizing(['broadcasts', 'default']);
})->with(['production', 'local', 'staging']);
