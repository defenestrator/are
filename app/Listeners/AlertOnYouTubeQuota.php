<?php

namespace App\Listeners;

use App\Events\YouTubeQuotaThresholdReached;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;

use function Sentry\captureMessage;

/**
 * Raises the 80% YouTube quota alert: a warning in the log and a Sentry event
 * (a no-op where no Sentry DSN is configured).
 */
class AlertOnYouTubeQuota
{
    public function handle(YouTubeQuotaThresholdReached $event): void
    {
        $message = sprintf(
            'YouTube quota: %d of %d %s used on %s (PT). Polling stops when it runs out.',
            $event->used,
            $event->limit,
            $event->bucket === 'search' ? 'search calls' : 'units',
            $event->day,
        );

        Log::warning($message, ['bucket' => $event->bucket, 'used' => $event->used, 'limit' => $event->limit, 'day' => $event->day]);
        captureMessage($message, Severity::warning());
    }
}
