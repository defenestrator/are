<?php

namespace App\Events;

use App\ControlBus\BusState;
use App\ControlBus\Game;
use App\Events\Concerns\BroadcastsWhenEnabled;
use App\Models\BusPublication;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The bus resolved an action for a game. Adapters (browser or Godot games)
 * subscribe to the public channel bus.{game} and listen for "bus.action".
 *
 * The kill switch, pause and veto were checked when the action was published.
 * They are checked again, fresh, when the event is dispatched (broadcastWhen)
 * and when the queued broadcast is sent (broadcastOn, which Laravel calls in
 * the job): a switch thrown in between still stops it.
 */
class BusActionPublished implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsWhenEnabled {
        broadcastWhen as broadcastingEnabled;
    }
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $game, public int $publicationId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // No channels means BroadcastEvent sends nothing.
        return $this->allowed() ? [new Channel('bus.'.$this->game)] : [];
    }

    public function broadcastAs(): string
    {
        return 'bus.action';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWhen(): bool
    {
        // No job at all while broadcasting is off (log or null driver):
        // adapters then poll GET /bus/{game}/actions instead.
        return $this->broadcastingEnabled() && $this->allowed();
    }

    private function allowed(): bool
    {
        $publication = BusPublication::find($this->publicationId);
        $game = Game::find($this->game);

        return $publication !== null
            && $game !== null
            && $publication->vetoed_at === null
            && BusState::read($game)->allowsPublishing();
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return BusPublication::findOrFail($this->publicationId)->payload();
    }
}
