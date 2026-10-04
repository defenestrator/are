<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Exceptions\IdentityLinkException;
use App\Identities;
use App\Models\LinkCode;
use App\Models\UserBan;
use Illuminate\Support\Facades\DB;

/**
 * !link CODE: link the chat account typing it to the ARE user who got the
 * code from Settings → Linked accounts. This is how YouTube viewers link
 * (Google sign-in is capped at 100 users until the app is verified, spike
 * #23), and Twitch chat can use it too.
 *
 * It serves unlinked chatters, so the registry's ban check (which needs a
 * user) does not cover the chat account. A banned chat account is refused here.
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
        $label = $invocation->provider->label();

        if ($invocation->arguments === '') {
            return ChatCommandResult::rejected('Usage: !link CODE. Get a code from Settings → Linked accounts at '.config('app.url').'.');
        }

        $code = LinkCode::findUsable($invocation->arguments);
        if ($code === null) {
            return ChatCommandResult::rejected('That link code is wrong or has expired. Get a new one from Settings → Linked accounts.');
        }

        if ($invocation->user !== null && $invocation->user->id === $code->user_id) {
            $code->consume();

            return ChatCommandResult::done("This {$label} account is already linked to you, {$invocation->chatterName}.");
        }

        if (UserBan::inEffect()->forAccount($invocation->provider, $invocation->chatterId)->exists()) {
            return ChatCommandResult::rejected(IdentityLinkException::accountBanned($invocation->provider)->getMessage());
        }

        try {
            DB::transaction(function () use ($code, $invocation) {
                // Consume first, inside the transaction, so two messages with
                // the same code cannot both link. A refused link rolls back and
                // leaves the code usable until it expires.
                if (! $code->consume()) {
                    throw new IdentityLinkException('That link code has just been used.');
                }

                Identities::linkAccount($code->user, $invocation->provider, $invocation->chatterId, [
                    'name' => $invocation->chatterName,
                ]);
            });
        } catch (IdentityLinkException $e) {
            return ChatCommandResult::rejected($e->getMessage());
        }

        return ChatCommandResult::done("Linked this {$label} account to {$code->user->name}. Your votes count once, wherever you vote.");
    }
}
