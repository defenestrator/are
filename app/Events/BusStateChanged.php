<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsWhenEnabled;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A game was paused or resumed, its mode changed, or the kill switch was
 * thrown or reset. Listen for "bus.state" on bus.{game}. Sent even while the
 * bus is killed: adapters must hear that they should stop.
 */
class BusStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsWhenEnabled, Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $game,
        public bool $killed,
        public bool $paused,
        public string $mode,
        public bool $running,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('bus.'.$this->game)];
    }

    public function broadcastAs(): string
    {
        return 'bus.state';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array{game: string, killed: bool, paused: bool, mode: string, running: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'game' => $this->game,
            'killed' => $this->killed,
            'paused' => $this->paused,
            'mode' => $this->mode,
            'running' => $this->running,
        ];
    }
}
