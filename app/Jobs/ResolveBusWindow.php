<?php

namespace App\Jobs;

use App\ControlBus\ControlBus;
use App\Models\BusWindow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes a vote window when it is due. Dispatched with a delay when the window
 * opens; `bus:resolve` on the scheduler catches any this misses. Resolving is
 * idempotent, so both may run.
 */
class ResolveBusWindow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $windowId)
    {
        // Same dedicated workers as the broadcasts it leads to, so a backlog
        // of slow jobs never delays a vote result.
        $this->onQueue('broadcasts');
    }

    public function handle(ControlBus $bus): void
    {
        $window = BusWindow::find($this->windowId);

        if ($window !== null) {
            $bus->resolve($window);
        }
    }
}
