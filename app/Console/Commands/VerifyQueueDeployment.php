<?php

namespace App\Console\Commands;

use App\Jobs\QueueDeploymentProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class VerifyQueueDeployment extends Command
{
    protected $signature = 'queue:verify-deployment
        {--restart : Terminate Horizon before checking the replacement workers}
        {--timeout=660 : Seconds to allow existing long jobs and probes to complete}';

    protected $description = 'Verify each Redis queue processes a probe with the deployed configuration revision';

    public function handle(): int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'redis') {
            $this->info('Redis queues are not enabled; Horizon deployment verification skipped.');

            return self::SUCCESS;
        }

        $revision = config('app.deployment_revision');
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
        if (! is_string($revision) || ! preg_match('/^[a-f0-9]{40}$/', $revision) || $timeout === false || $timeout < 1 || $timeout > 3600) {
            $this->error('Cache a full ARE_DEPLOY_REVISION and use a timeout between 1 and 3600 seconds.');

            return self::FAILURE;
        }
        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            $this->error('Queue verification needs a cache store shared by the command and workers.');

            return self::FAILURE;
        }
        $deadline = microtime(true) + $timeout;
        if ($this->option('restart')) {
            $repository = app(MasterSupervisorRepository::class);
            $oldNames = collect($repository->all())->pluck('name')->all();
            if ($this->call('horizon:terminate') !== self::SUCCESS) {
                return self::FAILURE;
            }
            // Do not hand a newly introduced probe class to an old worker.
            do {
                $replacement = collect($repository->all())->contains(
                    fn ($master) => $master->status === 'running' && ! in_array($master->name, $oldNames, true),
                );
                if ($replacement) {
                    break;
                }
                usleep(250000);
            } while (microtime(true) < $deadline);
            if (! $replacement) {
                $this->error('No replacement Horizon master appeared before the verification deadline. Existing jobs may still be draining; no jobs were deleted.');

                return self::FAILURE;
            }
        }

        $pending = [];
        foreach (['redis:broadcasts', 'redis:default', 'redis-long:clips'] as $lane) {
            [$connection, $queue] = explode(':', $lane, 2);
            $key = 'queue-deployment:'.Str::uuid();
            QueueDeploymentProbe::dispatch($key, now()->getTimestamp() + $timeout)
                ->onConnection($connection)->onQueue($queue);
            $pending[$lane] = $key;
        }

        $keys = array_values($pending);
        $this->info("Waiting for deployed revision {$revision} on broadcasts, default and clips...");
        try {
            do {
                foreach ($pending as $lane => $key) {
                    $result = Cache::get($key);
                    if (is_array($result)) {
                        if (($result['revision'] ?? null) !== $revision) {
                            // A worker may reserve the first probe while TERM propagates.
                            // Keep checking until the replacement consumes one, within the deadline.
                            Cache::forget($key);
                            [$connection, $queue] = explode(':', $lane, 2);
                            QueueDeploymentProbe::dispatch($key, now()->getTimestamp() + max(1, (int) ($deadline - microtime(true))))
                                ->onConnection($connection)->onQueue($queue)->delay(now()->addSecond());

                            continue;
                        }
                        $this->info("{$lane} processed the deployed revision.");
                        unset($pending[$lane]);
                    }
                }
                if ($pending === []) {
                    return self::SUCCESS;
                }
                usleep(250000);
            } while (microtime(true) < $deadline);

            $this->error('No deployed worker acknowledgement for: '.implode(', ', array_keys($pending)));

            return self::FAILURE;
        } finally {
            // Remove only this invocation's results; no business jobs are touched.
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
    }
}
