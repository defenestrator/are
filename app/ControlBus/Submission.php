<?php

namespace App\ControlBus;

use App\Models\BusBallot;

/**
 * The outcome of one chat action: its audit row and why it went the way it
 * did. There is deliberately no reply text here: replies post as the
 * broadcaster, so DoAction turns the reason into a fixed template (#128)
 * and never echoes the action, the option or any error text.
 */
final readonly class Submission
{
    /** Accepted: counted, published, or sent for approval. */
    public const ACCEPTED = 'accepted';

    public const NO_GAME = 'no_game';

    public const INVALID = 'invalid';

    public const NO_SUCH_OPTION = 'no_such_option';

    public const ANARCHY_REFERENCE = 'anarchy_reference';

    public const RATE_LIMITED = 'rate_limited';

    public const PAUSED = 'paused';

    public const KILLED = 'killed';

    public const VETOED_OPTION = 'vetoed_option';

    public const SAT_OUT = 'sat_out';

    public function __construct(
        public BusBallot $ballot,
        public string $reason,
    ) {}

    public function accepted(): bool
    {
        return $this->ballot->status->accepted();
    }
}
