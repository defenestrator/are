<?php

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * The /vote page component, as the signed-in user. Since #180 its cards are
 * Blade components, and their actions (upvote, downvote, deleteQuestion)
 * belong to this component and take the question id.
 */
function onVotePage(): Testable
{
    return Livewire::test(FragmentAlias::encode('vote', resource_path('views/vote.blade.php')));
}

/**
 * One question card as /vote renders it, for the signed-in user. Pass a
 * question from Question::getSortedQuestions() or cachedQueue(), which
 * carry `votes` and the author's identities.
 */
function cardHtml(Question $question, int $userVote = 0, ?bool $canModerate = null): string
{
    $canModerate ??= auth()->user()?->can('moderate') ?? false;

    return Blade::render('<x-question-card :question="$q" :user-vote="$v" :can-moderate="$m" />', [
        'q' => $question,
        'v' => $userVote,
        'm' => $canModerate,
    ]);
}
