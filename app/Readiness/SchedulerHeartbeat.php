<?php

namespace App\Readiness;

use Illuminate\Support\Carbon;

/**
 * Proof that `php artisan schedule:run` runs: a scheduled task touches this
 * file every minute. A file, not the cache, so a deploy's cache:clear does
 * not make the scheduler look dead.
 */
class SchedulerHeartbeat
{
    public static function path(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }

    public static function beat(): void
    {
        file_put_contents(self::path(), (string) now()->getTimestamp(), LOCK_EX);
    }

    public static function last(): ?Carbon
    {
        $contents = @file_get_contents(self::path());

        return is_string($contents) && ctype_digit(trim($contents))
            ? Carbon::createFromTimestamp((int) trim($contents))
            : null;
    }
}
