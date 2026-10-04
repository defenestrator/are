<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsWhenEnabled;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A viewer added a question to the queue. Only the id is broadcast: the vote
 * page reloads the queue itself, so no question text or author details go
 * over the public socket.
 */
class QuestionSubmitted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsWhenEnabled, Dispatchable, InteractsWithSockets;

    public function __construct(public int $questionId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('questions')];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array{id: int}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->questionId];
    }
}
