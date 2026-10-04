<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\ControlBus\ControlBus;

/**
 * !do <action> drives the running chat game, as the linked user: for Chat
 * Plays Orkestera, "!do task Write the README". "!do #2" backs option 2 of
 * the open vote. The Chat Control Bus decides what happens (see ControlBus).
 */
class DoAction implements ChatCommand
{
    public function __construct(private ControlBus $bus) {}

    public function names(): array
    {
        return ['do'];
    }

    public function requiresUser(): bool
    {
        // One person, one vote: ballots belong to users, not platform accounts.
        return true;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        $submission = $this->bus->submit(
            $invocation->user,
            $invocation->provider,
            $invocation->messageId,
            $invocation->arguments,
        );

        return $submission->accepted()
            ? ChatCommandResult::done($submission->reply)
            : ChatCommandResult::rejected($submission->reply);
    }
}
