<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\ControlBus\BallotStatus;
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

        // The registry posts every non-empty reply back to chat (#89). In a
        // chat game the votes are the chat, so an accepted vote gets no reply:
        // answering each one would double the flood and spend the channel's
        // reply budget. Refusals are answered, except a rate limit, for the
        // same reason the registry never answers "slow down".
        if ($submission->accepted()) {
            return ChatCommandResult::done();
        }

        return ChatCommandResult::rejected(
            $submission->ballot->status === BallotStatus::RateLimited ? '' : $submission->reply,
        );
    }
}
