<?php

use App\Jobs\QueueDeploymentProbe;
use App\Readiness\ReadinessChecks;
use App\Readiness\Status;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

test('queue deployment verification skips non Redis queues without dispatching', function () {
    Queue::fake();
    $this->artisan('queue:verify-deployment')->assertSuccessful();
    Queue::assertNothingPushed();
});

test('queue deployment verification refuses a missing cached revision before dispatch', function () {
    config(['queue.default' => 'redis', 'app.deployment_revision' => null]);
    Queue::fake();
    $this->artisan('queue:verify-deployment')->assertFailed();
    Queue::assertNothingPushed();
});

test('a deployment probe acknowledges the worker configuration revision without reading disk', function () {
    config(['app.deployment_revision' => str_repeat('a', 40)]);
    (new QueueDeploymentProbe('probe-test', now()->addMinute()->getTimestamp()))->handle();
    expect(Cache::get('probe-test')['revision'])->toBe(str_repeat('a', 40));
});

test('expired deployment probes have no effect', function () {
    (new QueueDeploymentProbe('expired-probe', now()->subSecond()->getTimestamp()))->handle();
    expect(Cache::has('expired-probe'))->toBeFalse();
});

test('queue verification exercises all three lanes and accepts only matching worker acknowledgements', function () {
    config(['queue.default' => 'redis', 'app.deployment_revision' => str_repeat('a', 40), 'cache.default' => 'file']);
    $lanes = [];
    Queue::shouldReceive('connection')->andReturnUsing(function ($connection) use (&$lanes) {
        $adapter = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
        $adapter->shouldReceive('push')->andReturnUsing(function ($job, $data, $queue) use ($connection, &$lanes) {
            $lanes[] = $connection.':'.$queue;
            $job->handle();

            return 'probe-id';
        });

        return $adapter;
    });
    $this->artisan('queue:verify-deployment', ['--timeout' => 1])->assertSuccessful();
    expect($lanes)->toBe(['redis:broadcasts', 'redis:default', 'redis-long:clips']);
});

test('unprocessed probes fail deployment within its deadline', function () {
    config(['queue.default' => 'redis', 'app.deployment_revision' => str_repeat('a', 40), 'cache.default' => 'file']);
    Queue::fake();
    $this->artisan('queue:verify-deployment', ['--timeout' => 1])->assertFailed();
    Queue::assertPushed(QueueDeploymentProbe::class, 3);
});

test('Horizon readiness rejects paused masters and missing queue processes', function (string $masterStatus, array $processes, Status $expected) {
    config(['queue.default' => 'redis']);
    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->shouldReceive('all')->andReturn([(object) ['name' => 'master', 'status' => $masterStatus]]);
    $supervisors = Mockery::mock(SupervisorRepository::class);
    $supervisors->shouldReceive('all')->andReturn([(object) ['master' => 'master', 'status' => 'running', 'processes' => $processes]]);
    app()->instance(MasterSupervisorRepository::class, $masters);
    app()->instance(SupervisorRepository::class, $supervisors);
    $checks = collect(app(ReadinessChecks::class)->queue());
    expect($checks->firstWhere('name', 'Horizon')->status)->toBe($expected);
})->with([
    ['paused', ['redis:broadcasts' => 1, 'redis:default' => 1, 'redis-long:clips' => 1], Status::Fail],
    ['running', ['redis:broadcasts' => 1, 'redis:default' => 1, 'redis-long:clips' => 0], Status::Fail],
    ['running', ['redis:broadcasts' => 1, 'redis:default' => 1, 'redis-long:clips' => 1], Status::Ok],
]);

test('stale worker acknowledgements cannot pass deployment verification', function () {
    config(['queue.default' => 'redis', 'app.deployment_revision' => str_repeat('a', 40), 'cache.default' => 'file']);
    Queue::shouldReceive('connection')->andReturnUsing(function () {
        $adapter = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
        $adapter->shouldReceive('push')->andReturnUsing(function ($job) {
            Cache::put($job->resultKey, ['revision' => str_repeat('b', 40)], 60);

            return 'stale-probe';
        });
        $adapter->shouldReceive('later')->andReturn('replacement-probe');

        return $adapter;
    });
    $this->artisan('queue:verify-deployment', ['--timeout' => 1])->assertFailed();
});

test('restart verification fails without dispatching when a replacement master never appears', function () {
    config(['queue.default' => 'redis', 'app.deployment_revision' => str_repeat('a', 40), 'cache.default' => 'file']);
    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->shouldReceive('all')->andReturn([]);
    app()->instance(MasterSupervisorRepository::class, $masters);
    Queue::fake();
    $this->artisan('queue:verify-deployment', ['--restart' => true, '--timeout' => 1])->assertFailed();
    Queue::assertNothingPushed();
});
