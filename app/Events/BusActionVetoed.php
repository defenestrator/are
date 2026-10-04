<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsWhenEnabled;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator vetoed an action already sent. Adapters should cancel or undo
 * it if they still can. Listen for "bus.veto" on bus.{game}.
 */
class BusActionVetoed implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsWhenEnabled, Dispatchable, InteractsWithSockets;

    public function __construct(public string $game, public int $publicationId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('bus.'.$this->game)];
    }

    public function broadcastAs(): string
    {
        return 'bus.veto';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array{id: int, game: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->publicationId, 'game' => $this->game];
    }
}
