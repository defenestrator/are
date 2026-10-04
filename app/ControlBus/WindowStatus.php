<?php

namespace App\ControlBus;

enum WindowStatus: string
{
    case Open = 'open';

    /** Closed and its winner published. */
    case Resolved = 'resolved';

    /** Closed with no votes. */
    case Empty = 'empty';

    /** Closed while the game was paused; nothing was published. */
    case Held = 'held';

    /** Closed while the kill switch was on; nothing was published. */
    case Killed = 'killed';

    /** Dropped by a moderator (mode or game change) before it closed. */
    case Cancelled = 'cancelled';
}
