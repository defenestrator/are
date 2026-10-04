<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Helix answered 401 to a request made with a viewer's token: it was revoked
 * or expired before its stored expiry time. The message never carries the token.
 */
class TwitchTokenRejected extends RuntimeException
{
    public static function forTwitchUser(string $twitchUserId): self
    {
        return new self("Helix rejected the access token of Twitch user {$twitchUserId} (HTTP 401).");
    }
}
