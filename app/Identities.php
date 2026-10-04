<?php

namespace App;

use App\Exceptions\IdentityLinkException;
use App\Models\Identity;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as ProviderAccount;
use Laravel\Socialite\Two\User as OAuth2Account;

/**
 * The one place that maps platform accounts to people. Sign-in, linking from
 * Settings and (later) chat ingestion all resolve a (provider, provider user
 * id) pair to a User here, so votes, limits and bans count once per person.
 */
class Identities
{
    /**
     * The user who owns this platform account, if anyone does.
     */
    public static function findUser(IdentityProvider $provider, string $providerUserId): ?User
    {
        return Identity::for($provider, $providerUserId)->first()?->user;
    }

    /**
     * Sign in with a platform account: return its owner, or create a new user
     * that owns it. Refreshes the identity's profile and tokens either way.
     */
    public static function signIn(IdentityProvider $provider, ProviderAccount $account): User
    {
        $identity = Identity::for($provider, (string) $account->getId())->first();

        if ($identity === null) {
            try {
                $identity = DB::transaction(function () use ($provider, $account) {
                    $user = User::create([
                        'name' => self::displayName($account),
                        'email' => $account->getEmail(),
                    ]);

                    return $user->identities()->create(self::attributes($provider, $account) + [
                        'provider' => $provider,
                        'provider_user_id' => (string) $account->getId(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                // A concurrent first sign-in created it; use that one.
                $identity = Identity::for($provider, (string) $account->getId())->firstOrFail();
            }

            return $identity->user;
        }

        $identity->update(self::attributes($provider, $account));
        $user = $identity->user;

        // The account a person first signed in with names them, so linking a
        // second platform does not make their name flip between logins.
        if ($user->identities()->oldest('id')->value('id') === $identity->id) {
            $user->update(['name' => self::displayName($account)]);
        }

        return $user;
    }

    /**
     * Link another platform account to a signed-in user. Linking an account
     * that is already this user's refreshes it. It never merges two users.
     *
     * @throws IdentityLinkException
     */
    public static function link(User $user, IdentityProvider $provider, ProviderAccount $account): Identity
    {
        $providerUserId = (string) $account->getId();
        $existing = Identity::for($provider, $providerUserId)->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw IdentityLinkException::ownedByAnotherUser($provider);
        }

        if ($existing !== null) {
            $existing->update(self::attributes($provider, $account));

            return $existing;
        }

        if ($user->identities()->where('provider', $provider)->exists()) {
            throw IdentityLinkException::providerAlreadyLinked($provider);
        }

        try {
            $identity = $user->identities()->create(self::attributes($provider, $account) + [
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Someone else, or this user in another tab, linked it meanwhile.
            throw Identity::for($provider, $providerUserId)->where('user_id', $user->id)->exists()
                ? IdentityLinkException::providerAlreadyLinked($provider)
                : IdentityLinkException::ownedByAnotherUser($provider);
        }

        $user->unsetRelation('identities');

        return $identity;
    }

    /**
     * Remove a linked account. A user always keeps at least one identity, and
     * a banned user cannot shed the identity a ban came through.
     *
     * @throws IdentityLinkException
     */
    public static function unlink(User $user, Identity $identity): void
    {
        if ($identity->user_id !== $user->id) {
            throw IdentityLinkException::notYours();
        }

        if ($user->isBanned()) {
            throw IdentityLinkException::banned();
        }

        DB::transaction(function () use ($user, $identity) {
            // Lock the rows, then count them: Postgres refuses FOR UPDATE on an aggregate.
            $ids = $user->identities()->lockForUpdate()->pluck('id');

            if ($ids->count() <= 1) {
                throw IdentityLinkException::lastIdentity();
            }

            // Twitch tiers come from the Twitch account. A user holds at most
            // one, so every tier row goes with it; re-linking re-syncs them.
            if ($identity->provider === IdentityProvider::Twitch) {
                UserTwitchSubscription::where('user_id', $user->id)->delete();
            }

            $identity->delete();
        });

        $user->unsetRelation('identities');
    }

    private static function displayName(ProviderAccount $account): string
    {
        return $account->getName() ?? $account->getNickname() ?? 'Viewer';
    }

    /**
     * Profile and token fields refreshed on every sign-in or link.
     *
     * @return array<string, mixed>
     */
    private static function attributes(IdentityProvider $provider, ProviderAccount $account): array
    {
        $attributes = [
            'name' => $account->getNickname() ?? $account->getName(),
            'email' => $account->getEmail(),
            'avatar_url' => $account->getAvatar(),
        ];

        if ($provider->storesTokens() && $account instanceof OAuth2Account) {
            $attributes['access_token'] = $account->token;
            $attributes['token_expires_at'] = $account->expiresIn ? now()->addSeconds((int) $account->expiresIn) : null;

            // Providers only send a refresh token on some grants; keep the old one otherwise.
            if ($account->refreshToken) {
                $attributes['refresh_token'] = $account->refreshToken;
            }
        }

        return $attributes;
    }
}
