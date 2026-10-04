<?php

namespace App;

/**
 * A platform a person can be known by. One user may hold one identity per
 * provider; votes, limits and bans attach to the user, never the identity.
 */
enum IdentityProvider: string
{
    case Twitch = 'twitch';
    case Facebook = 'facebook';

    // Sign-in arrives with #7 (Google). Present now so chat ingestion and
    // tests can attach YouTube identities to a user.
    case YouTube = 'youtube';

    public function label(): string
    {
        return match ($this) {
            self::Twitch => 'Twitch',
            self::Facebook => 'Facebook',
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
     * Providers offered on the sign-in and linked-accounts screens.
     *
     * @return list<self>
     */
    public static function signInProviders(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->supportsSignIn()));
    }

    /**
     * Whether to keep this provider's OAuth tokens. Twitch tokens read
     * subscriptions and chat; Facebook's are not used, so are not kept.
     */
    public function storesTokens(): bool
    {
        return $this !== self::Facebook;
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
