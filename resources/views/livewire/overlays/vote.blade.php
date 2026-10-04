<?php

use App\Enums\Overlay;
use App\Livewire\Overlays\PollingOverlay;
use App\Models\Question;
use App\Models\Topic;

/*
 * The vote leaderboard: the "Top Suggestions" column of /vote, under the
 * current topic. Kept live by resources/js/live-overlay.js.
 */
new class extends PollingOverlay {
    protected function overlay(): Overlay
    {
        return Overlay::Vote;
    }

    public function getListeners(): array
    {
        return ['echo:topic,TopicChanged' => '$refresh'];
    }

    public function with(): array
    {
        $current = $this->tokenIsCurrent();
        $limit = $this->isVertical() ? 3 : 5;

        return [
            'current' => $current,
            'limit' => $limit,
            'topic' => $current ? Topic::current()?->topic : null,
            'questions' => $current ? Question::cachedQueue()['top']->take($limit) : collect(),
        ];
    }
}; ?>

<div class="absolute right-16 top-16 w-[600px] vertical:inset-x-14 vertical:top-[200px] vertical:w-auto">
    <div x-data="liveOverlay" data-live="{{ $current ? 'on' : 'off' }}" data-live-mode="top" data-live-limit="{{ $limit }}">
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
        @endif

        <ol x-ref="list" class="flex flex-col gap-3 vertical:gap-4">
            @foreach ($questions as $question)
                <li wire:key="vote-{{ $question->id }}" data-question-id="{{ $question->id }}">
                    <x-overlay.question-card :question="$question" :rank="$loop->iteration" />
                </li>
            @endforeach
        </ol>
    </div>
</div>
