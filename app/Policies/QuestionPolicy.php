<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    /**
     * Authors may delete their own question; moderators may delete any.
     */
    public function delete(User $user, Question $question): bool
    {
        return $user->id === $question->user_id || $user->can('moderate');
    }

    /**
     * Fold this duplicate into another question.
     */
    public function merge(User $user, Question $question): bool
    {
        return $user->can('moderate');
    }
}
