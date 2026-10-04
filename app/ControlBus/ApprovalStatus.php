<?php

namespace App\ControlBus;

enum ApprovalStatus: string
{
    /** Waiting for a moderator. */
    case Pending = 'pending';

    /** A moderator approved it and it was published. */
    case Approved = 'approved';

    /** A moderator rejected it, or nobody decided before it expired. */
    case Rejected = 'rejected';

    /** Dropped by the kill switch. */
    case Cancelled = 'cancelled';
}
