<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Exceptions\IdentityLinkException;
use App\Models\Identity;
use App\Models\LinkCode;
use App\Models\UserBan;

/**
 * !link CODE: propose linking the chat account typing it to the ARE user who
 * got the code from Settings → Linked accounts. This is how YouTube viewers
 * link (Google sign-in is capped at 100 users until the app is verified,
 * spike #23), and Twitch chat can use it too.
 *
 * Typing the code links nothing. It records a pending link that the code's
 * owner must confirm in Settings, seeing the account's name first (see
 * LinkCode). Otherwise anyone talked into typing someone else's code would
 * have their account attached to that person, and their !q and !vote would
 * act as them.
 *
 * It serves unlinked chatters, so the registry's ban check (which needs a
 * user) does not cover the chat account. A banned chat account is refused here.
 * The linking rules are checked now, for a clear answer in chat, and again
 * when the owner confirms.
 */
class LinkAccount implements ChatCommand
{
    public function names(): array
    {
        return ['link'];
    }

    public function requiresUser(): bool
    {
        return false;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        $provider = $invocation->provider;

        if ($invocation->arguments === '') {
            return ChatCommandResult::rejected('Usage: !link CODE. Get a code from Settings → Linked accounts at '.config('app.url').'.');
        }

        $code = LinkCode::findUsable($invocation->arguments);
        if ($code === null) {
            return ChatCommandResult::rejected('That link code is wrong, expired or already used. Get a new one from Settings → Linked accounts.');
        }

        if ($invocation->user !== null && $invocation->user->id === $code->user_id) {
            $code->consume();

            return ChatCommandResult::done("This {$provider->label()} account is already linked to you, {$invocation->chatterName}.");
        }

        if (UserBan::inEffect()->forAccount($provider, $invocation->chatterId)->exists()) {
            return ChatCommandResult::rejected(IdentityLinkException::accountBanned($provider)->getMessage());
        }

        $owner = $code->user;
        $refusal = match (true) {
            $owner->isBanned() => IdentityLinkException::bannedLink(),
            Identity::for($provider, $invocation->chatterId)->exists() => IdentityLinkException::ownedByAnotherUser($provider),
            $owner->identities()->where('provider', $provider)->exists() => IdentityLinkException::providerAlreadyLinked($provider),
            default => null,
        };
        if ($refusal !== null) {
            return ChatCommandResult::rejected($refusal->getMessage());
        }

        if (! $code->claim($provider, $invocation->chatterId, $invocation->chatterName)) {
            return ChatCommandResult::rejected('That link code has just been used.');
        }

        return ChatCommandResult::done("Almost done, {$invocation->chatterName}: confirm this {$provider->label()} account in Settings → Linked accounts within ".LinkCode::MINUTES.' minutes.');
    }
}
