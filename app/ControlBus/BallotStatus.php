<?php

namespace App\ControlBus;

/**
 * What happened to one chat action. Every action gets a ballot row with one
 * of these, so the audit log shows refusals as well as votes.
 */
enum BallotStatus: string
{
    /** Counts in its window's tally. */
    case Counted = 'counted';

    /** The same person voted again in the window; their newer ballot counts. */
    case Replaced = 'replaced';

    /** Anarchy: published straight away. */
    case Published = 'published';

    /** Anarchy: a free-text action waiting for a moderator (see BusApproval). */
    case PendingApproval = 'pending_approval';

    /**
     * A moderator vetoed this option in its window. Also given to a refused
     * attempt to back a vetoed option, and to anything more from someone
     * who backed one, for the rest of that window.
     */
    case Vetoed = 'vetoed';

    /** Anarchy: the person sent too many actions. */
    case RateLimited = 'rate_limited';

    /** The game is paused. */
    case Paused = 'paused';

    /** The kill switch is on, or the bus is disabled. */
    case Killed = 'killed';

    /** No game is running. */
    case NoGame = 'no_game';

    /** Not an action this game understands. */
    case Invalid = 'invalid';

    public function accepted(): bool
    {
        return in_array($this, [self::Counted, self::Replaced, self::Published, self::PendingApproval], true);
    }
}
