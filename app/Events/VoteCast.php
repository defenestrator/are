<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A question's vote total changed. The browser writes the new total into that
 * question's cards and re-sorts the list straight from this payload, with no
 * server round-trip. The payload is the total, never who voted.
 *
 * $version is the question's vote_version, read under the same row lock as
 * the total (Question::announceVoteChange), so a browser can ignore a VoteCast
 * that arrives after a newer one.
 */
class VoteCast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $questionId, public int $votes, public int $version) {}

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
     * @return array{question_id: int, votes: int, version: int}
     */
    public function broadcastWith(): array
    {
        return ['question_id' => $this->questionId, 'votes' => $this->votes, 'version' => $this->version];
    }
}
