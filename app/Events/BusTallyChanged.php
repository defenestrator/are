<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The open vote, a pending approval or the latest result of a game changed.
 * Listen for "bus.tally" on bus.{game}: the payload is BusOverlay::snapshot(),
 * which has no user data and no unapproved free text (#138).
 *
 * It is sent by the BroadcastBusTally job, never directly, so a burst of
 * ballots costs one broadcast. Hence ShouldBroadcastNow: the job is
 * already on the broadcasts queue.
 */
class BusTallyChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(public string $game, public array $snapshot) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('bus.'.$this->game)];
    }

    public function broadcastAs(): string
    {
        return 'bus.tally';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot;
    }
}
