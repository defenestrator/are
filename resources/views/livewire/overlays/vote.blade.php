<?php

use App\Enums\Overlay;
use App\Livewire\Overlays\PollingOverlay;
use App\Models\Question;
use App\Models\Topic;

/*
 * The vote leaderboard: the "Top Suggestions" column of /vote, under the
 * current topic.
 */
new class extends PollingOverlay {
    protected function overlay(): Overlay
    {
        return Overlay::Vote;
    }

    public function with(): array
    {
        $current = $this->tokenIsCurrent();

        return [
            'current' => $current,
            'topic' => $current ? Topic::current()?->topic : null,
            'questions' => $current ? Question::getSortedQuestions($this->isVertical() ? 3 : 5) : collect(),
        ];
    }
}; ?>

<div @if ($current) wire:poll.5s.keep-alive @endif
     class="absolute right-16 top-16 w-[600px] vertical:inset-x-14 vertical:top-[200px] vertical:w-auto">
    @if ($questions->isEmpty())
        <x-overlay.empty message="No suggestions to vote on yet." />
    @else
        <header class="overlay-enter mb-4 [text-shadow:0_2px_8px_rgb(0_0_0/0.6)]">
            <h2 class="flex items-center gap-3 text-lg font-semibold uppercase tracking-[0.2em] text-white/80 vertical:text-2xl">
                <span class="size-2.5 rounded-full bg-[#7c5cff] vertical:size-3.5"></span>
                Top suggestions
            </h2>
            @if ($topic)
                <p class="mt-1 truncate text-2xl font-semibold text-white vertical:text-[2.25rem]">{{ $topic }}</p>
            @endif
        </header>

        <ol class="flex flex-col gap-3 vertical:gap-4">
            @foreach ($questions as $question)
                <li wire:key="vote-{{ $question->id }}">
                    <x-overlay.question-card :question="$question" :rank="$loop->iteration" />
                </li>
            @endforeach
        </ol>
    @endif
</div>
