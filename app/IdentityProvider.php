<?php

namespace App;

/**
 * A platform a person can be known by. One user may hold one identity per
 * provider; votes, limits and bans attach to the user, never the identity.
 */
enum IdentityProvider: string
{
    case Twitch = 'twitch';

    // Sign-in arrives with #7 (Google). Present now so chat ingestion and
    // tests can attach YouTube identities to a user.
    case YouTube = 'youtube';

    public function label(): string
    {
        return match ($this) {
            self::Twitch => 'Twitch',
            self::YouTube => 'YouTube',
        };
    }

    /**
     * Whether people can sign in and link this provider through Socialite yet.
     */
    public function supportsSignIn(): bool
    {
        return $this !== self::YouTube;
    }

    /**
     * Whether Settings offers linking this provider with a one-time code typed
     * into its chat (`!link CODE`). YouTube links this way because Google
     * sign-in for viewers is capped until the app is verified (spike #23).
     * Twitch chat accepts codes too, but Twitch also has a sign-in button.
     */
    public function linksThroughChat(): bool
    {
        return $this === self::YouTube;
    }

    /**
     * Providers offered on the sign-in and linked-accounts screens.
     *
     * @return list<self>
     */
    public static function signInProviders(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->supportsSignIn()));
    }

    /**
     * OAuth scopes requested when signing in or linking.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Twitch => ['user:read:chat', 'user:read:subscriptions'],
            default => [],
        };
    }
}
