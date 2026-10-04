<?php

namespace App\Chat;

/**
 * One chat command, such as !q or !vote, on any platform.
 *
 * Register an implementation by tagging it 'chat.commands' in
 * ChatCommandServiceProvider. ChatCommandRegistry parses the message, resolves
 * the chatter to a user, refuses banned users, rate-limits and then calls
 * handle(). A command only does its own work.
 */
interface ChatCommand
{
    /**
     * The names this command answers to: lowercase, without the "!".
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * Whether the chatter must be linked to a user. If true, handle() always
     * receives a non-null $invocation->user. Return false only for commands
     * that serve unlinked chatters, such as !link CODE.
     */
    public function requiresUser(): bool;

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult;
}
