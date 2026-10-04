<?php

namespace App\Chat;

enum ChatCommandStatus: string
{
    /** The command did its work. */
    case Done = 'done';

    /** The command refused the request (bad arguments, a limit, a closed question). */
    case Rejected = 'rejected';

    /** The chatter is not linked to a user and the command requires one. */
    case Unlinked = 'unlinked';

    /** The chatter's user is banned or timed out. */
    case Banned = 'banned';

    /** The chatter sent too many commands. */
    case RateLimited = 'rate_limited';

    /** The command threw. The error is reported, and the chat job is not retried. */
    case Failed = 'failed';
}
