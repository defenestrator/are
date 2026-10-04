<?php

namespace App\ControlBus;

enum WindowStatus: string
{
    case Open = 'open';

    /** Closed and its winner published. */
    case Resolved = 'resolved';

    /** Closed; its free-text winner waits for a moderator (see BusApproval). */
    case AwaitingApproval = 'awaiting_approval';

    /** Its winner was rejected by a moderator, or the approval expired. */
    case Rejected = 'rejected';

    /** Closed with no votes. */
    case Empty = 'empty';

    /** Closed while the game was paused; nothing was published. */
    case Held = 'held';

    /** Closed while the kill switch was on; nothing was published. */
    case Killed = 'killed';

    /** Dropped before it closed: a mode or game change, or the kill switch. */
    case Cancelled = 'cancelled';
}
