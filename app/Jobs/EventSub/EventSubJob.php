<?php

namespace App\Jobs\EventSub;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One EventSub notification, handled off the request.
 *
 * Idempotent on the Twitch message id, which Twitch keeps the same when it
 * redelivers: ShouldBeUnique stops a second copy being queued while the first
 * is pending, and a marker written after success makes a later copy a no-op.
 * The marker is written only after process() succeeds, so a failure retries.
 * Jobs that persist rows also upsert on Twitch's own id, so a lost marker
 * still cannot create a duplicate.
 */
abstract class EventSubJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    /** How long the queued-copy lock lasts if a worker dies holding it. */
    public int $uniqueFor = 3600;

    /**
     * @param  string  $messageId  Twitch-Eventsub-Message-Id
     * @param  string  $sentAt  Twitch-Eventsub-Message-Timestamp (RFC 3339)
     * @param  array<string, mixed>  $event  The notification's `event` object
     */
    public function __construct(
        public string $messageId,
        public string $sentAt,
        public array $event,
    ) {}

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(): void
    {
        $handledKey = 'twitch.eventsub.handled.'.$this->messageId;
        if (Cache::has($handledKey)) {
            return;
        }

        $this->process();

        Cache::put($handledKey, true, now()->addDay());
    }

    abstract protected function process(): void;

    protected function sentAt(): Carbon
    {
        return Carbon::parse($this->sentAt);
    }

    protected function string(string $key): string
    {
        return (string) ($this->event[$key] ?? '');
    }
}
