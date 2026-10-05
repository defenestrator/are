<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Exceptions\QuestionRejected;
use App\QuestionQueue;

/**
 * !q <text>: add a question to the queue, as the linked user.
 */
class SubmitQuestion implements ChatCommand
{
    public function names(): array
    {
        return ['q'];
    }

    public function requiresUser(): bool
    {
        return true;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        try {
            $question = QuestionQueue::submit($invocation->user, $invocation->arguments, $invocation->provider->value);
        } catch (QuestionRejected $e) {
            return ChatCommandResult::rejected($e->getMessage());
        }

        return ChatCommandResult::done("Question #{$question->id} is in the queue. Vote with !vote {$question->id}");
    }
}
