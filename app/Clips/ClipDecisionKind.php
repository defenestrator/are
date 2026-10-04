<?php

namespace App\Clips;

enum ClipDecisionKind: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** The title or trim changed. An approved clip goes back to review. */
    case Edited = 'edited';
}
