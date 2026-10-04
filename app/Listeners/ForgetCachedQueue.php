<?php

namespace App\Listeners;

use App\Events\QuestionArchived;
use App\Events\QuestionSubmitted;
use App\Events\VoteCast;
use App\Models\Question;

/**
 * Anything that changes the queue retires the vote page's cached lists. The
 * events dispatch after commit, so the next read sees the change.
 */
class ForgetCachedQueue
{
    public function handle(QuestionSubmitted|VoteCast|QuestionArchived $event): void
    {
        Question::forgetCachedQueue();
    }
}
