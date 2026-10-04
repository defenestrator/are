<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsWhenEnabled;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Questions left the queue: archived when a moderator clears the topic,
 * deleted by their author or a moderator, or merged into another question.
 *
 * $questionIds lists the questions that left, or is null when the whole queue
 * was archived at once, so clearing a long queue cannot outgrow a message.
 */
class QuestionArchived implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsWhenEnabled, Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>|null  $questionIds
     */
    public function __construct(public ?array $questionIds) {}

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
     * @return array{ids: list<int>|null}
     */
    public function broadcastWith(): array
    {
        return ['ids' => $this->questionIds];
    }
}
