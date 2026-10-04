<?php

namespace App\Exceptions;

use App\IdentityProvider;
use RuntimeException;

/**
 * A link or unlink was refused. The message is safe to show the user.
 */
class IdentityLinkException extends RuntimeException
{
    public static function ownedByAnotherUser(IdentityProvider $provider): self
    {
        return new self("That {$provider->label()} account is already linked to a different ARE account, so it cannot be linked to this one. Accounts are never merged automatically.");
    }

    public static function providerAlreadyLinked(IdentityProvider $provider): self
    {
        return new self("You already have a {$provider->label()} account linked. Unlink it before linking another.");
    }

    public static function lastIdentity(): self
    {
        return new self('You cannot unlink your only account, or you would have no way to sign in.');
    }

    public static function notYours(): self
    {
        return new self('That account is not linked to you.');
    }

    public static function banned(): self
    {
        return new self('You cannot unlink accounts while you are banned or timed out.');
    }
}
