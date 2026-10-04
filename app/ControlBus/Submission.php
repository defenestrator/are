<?php

namespace App\ControlBus;

use App\Models\BusBallot;

/**
 * The outcome of one chat action: its audit row and the reply for chat.
 */
final readonly class Submission
{
    public function __construct(
        public BusBallot $ballot,
        public string $reply,
    ) {}

    public function accepted(): bool
    {
        return $this->ballot->status->accepted();
    }
}
