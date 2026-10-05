<?php

use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Redis\Connector;
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

test('horizon:snapshot only runs once the queue is on Redis', function (string $queue, bool $runs) {
    config(['queue.default' => $queue]);
    $this->travelTo(now()->setTime(12, 5));

    $snapshot = collect(app(Schedule::class)->dueEvents(app()))
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'horizon:snapshot'));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->filtersPass(app()))->toBe($runs);
})->with([
    'database queue (production before Redis)' => ['database', false],
    'sync queue' => ['sync', false],
    'redis queue' => ['redis', true],
]);

test('every environment runs a broadcasts supervisor that never scales to zero', function (string $environment) {
    $supervisor = array_replace(
        config('horizon.defaults.supervisor-broadcasts'),
        config("horizon.environments.{$environment}.supervisor-broadcasts"),
    );

    expect($supervisor['queue'])->toBe(['broadcasts'])
        ->and($supervisor['minProcesses'])->toBeGreaterThanOrEqual(1);
})->with(['production', 'local', '*']);

test('every supervisor times out before its own queue connection retries the job', function (string $environment) {
    foreach (config("horizon.environments.{$environment}") as $name => $overrides) {
        $supervisor = array_replace(config("horizon.defaults.{$name}", []), $overrides);
        $retryAfter = config("queue.connections.{$supervisor['connection']}.retry_after");

        expect($retryAfter)->toBeInt("{$environment}.{$name}: no queue connection {$supervisor['connection']}")
            ->and($supervisor['timeout'])->toBeLessThan($retryAfter - 5, "{$environment}.{$name}");
    }
})->with(['production', 'local', '*']);

test('Horizon starts every supervisor whatever APP_ENV is', function (string $environment) {
    Event::fake();
    $queues = [];
    $this->mock(HorizonCommandQueue::class)
        ->shouldReceive('push')
        ->andReturnUsing(function ($_, $command, array $options) use (&$queues) {
            $queues[] = $options['queue'];
        });

    ProvisioningPlan::get('test-master')->deploy($environment);

    expect($queues)->toEqualCanonicalizing(['broadcasts', 'default', 'clips']);
})->with(['production', 'local', 'staging']);

/**
 * Make every Redis connection attempt fail, as on production before
 * REDIS_PASSWORD is set (NOAUTH) or while Redis is down.
 */
function redisIsUnreachable(): void
{
    app()->forgetInstance('redis');
    app('redis')->setDriver('unreachable');
    app('redis')->extend('unreachable', fn () => new class implements Connector
    {
        public function connect(array $config, array $options)
        {
            throw new RuntimeException('Redis is unreachable.');
        }

        public function connectToCluster(array $config, array $clusterOptions, array $options)
        {
            throw new RuntimeException('Redis is unreachable.');
        }
    });
}

test('non-broadcasters get a clean 403 from Horizon while Redis is unreachable', function (string $path) {
    redisIsUnreachable();
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    $this->get($path)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
    $this->actingAs($mod)->get($path)->assertForbidden();
})->with(['/horizon', '/horizon/api/stats', '/horizon/api/jobs/failed']);

test('the Horizon page itself loads for a broadcaster while Redis is unreachable', function () {
    // Only its API calls need Redis; those return 500 until REDIS_PASSWORD is set.
    redisIsUnreachable();

    $this->actingAs(User::factory()->twitch('1000')->create())->get('/horizon')->assertOk();
});

test('only broadcasters see the Horizon link in the nav, as a full page load', function () {
    $link = 'href="'.url(config('horizon.path')).'"';

    $broadcaster = User::factory()->twitch('1000')->create();
    $this->actingAs($broadcaster)->get('/vote')
        ->assertOk()
        ->assertSee($link, false)
        ->assertSee('Horizon');

    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);
    expect($mod->can('moderate'))->toBeTrue();
    $this->actingAs($mod)->get('/vote')->assertOk()->assertDontSee($link, false);

    $this->actingAs(User::factory()->create())->get('/vote')->assertOk()->assertDontSee($link, false);
});

test('the Horizon nav link follows the viewHorizon gate, so a banned broadcaster does not get it', function () {
    $broadcaster = User::factory()->twitch('1000')->create();
    $broadcaster->localBans()->create(['moderator_id' => $broadcaster->id]);

    expect($broadcaster->can('viewHorizon'))->toBeFalse();

    // Banned users are bounced off /vote, so render the header directly as them.
    $this->actingAs($broadcaster);
    $html = view('components.layouts.app.header', ['title' => 'x', 'slot' => ''])->render();

    expect($html)->not->toContain('href="'.url(config('horizon.path')).'"');
});
