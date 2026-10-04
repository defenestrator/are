<?php

namespace App\Events\Concerns;

/**
 * Broadcast only when a real broadcaster is configured. Laravel queues a
 * BroadcastEvent job for every ShouldBroadcast event whatever the driver,
 * so with BROADCAST_CONNECTION=log or null (production until Reverb is set
 * up) each vote would queue a job that either piles up in `jobs` or writes
 * its payload to the log. Listeners on the event still run either way.
 */
trait BroadcastsWhenEnabled
{
    public function broadcastWhen(): bool
    {
        $connection = config('broadcasting.default');
        $driver = config("broadcasting.connections.{$connection}.driver", $connection);

        return ! in_array($driver, ['log', 'null', null], true);
    }
}
