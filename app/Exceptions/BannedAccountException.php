<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Signing in was refused because a local ban covers the platform account.
 */
class BannedAccountException extends RuntimeException
{
    //
}
