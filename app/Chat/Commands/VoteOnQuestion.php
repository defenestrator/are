<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Exceptions\QuestionRejected;
use App\Models\Question;
use App\QuestionQueue;

/**
 * !vote <id> [up|down]: vote on a question, as the linked user. Up by default.
 * The id may be written with or without a leading "#".
 */
class VoteOnQuestion implements ChatCommand
{
    private const DIRECTIONS = ['' => 1, 'up' => 1, '+' => 1, '+1' => 1, 'down' => -1, '-' => -1, '-1' => -1];

    public function names(): array
    {
        return ['vote'];
    }

    public function requiresUser(): bool
    {
        return true;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        $usage = ChatCommandResult::rejected('Usage: !vote <question number> [up|down]');

        if (! preg_match('/^#?(\d{1,18})(?:\s+(\S+))?$/u', $invocation->arguments, $m)) {
            return $usage;
        }

        $direction = self::DIRECTIONS[strtolower($m[2] ?? '')] ?? null;
        if ($direction === null) {
            return $usage;
        }

        $question = Question::find((int) $m[1]);
        if ($question === null) {
            return ChatCommandResult::rejected("There is no question #{$m[1]}.");
        }

        try {
            QuestionQueue::vote($invocation->user, $question, $direction);
        } catch (QuestionRejected $e) {
            return ChatCommandResult::rejected($e->getMessage());
        }

        return ChatCommandResult::done(($direction > 0 ? 'Upvoted' : 'Downvoted')." question #{$question->id}.");
    }
}
