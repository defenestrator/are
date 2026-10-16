<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueueDeploymentProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public string $resultKey, public int $expiresAt) {}

    public function handle(): void
    {
        if (now()->getTimestamp() >= $this->expiresAt) {
            return;
        }

        // Read the worker's booted configuration, never Git or a newly changed file.
        Cache::put($this->resultKey, [
            'revision' => config('app.deployment_revision'),
            'pid' => getmypid(),
        ], now()->addMinutes(15));
    }
}
