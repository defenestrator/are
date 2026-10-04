<?php

use App\Enums\Overlay;
use App\Livewire\Overlays\PollingOverlay;
use App\Models\Question;

/*
 * The newest questions in the queue: the "New Ideas" column of /vote.
 */
new class extends PollingOverlay {
    protected function overlay(): Overlay
    {
        return Overlay::Queue;
    }

    public function with(): array
    {
        $current = $this->tokenIsCurrent();

        return [
            'current' => $current,
            'questions' => $current ? Question::getRecentQuestions($this->isVertical() ? 3 : 5) : collect(),
        ];
    }
}; ?>

<div @if ($current) wire:poll.5s.keep-alive @endif
     class="absolute right-16 top-16 w-[600px] vertical:inset-x-14 vertical:top-[200px] vertical:w-auto">
    @if ($questions->isEmpty())
        <x-overlay.empty message="No questions in the queue yet." />
    @else
        <h2 class="overlay-enter mb-4 flex items-center gap-3 text-lg font-semibold uppercase tracking-[0.2em] text-white/80 [text-shadow:0_2px_8px_rgb(0_0_0/0.6)] vertical:text-2xl">
            <span class="size-2.5 rounded-full bg-[#7c5cff] vertical:size-3.5"></span>
            New ideas
        </h2>

        <ol class="flex flex-col gap-3 vertical:gap-4">
            @foreach ($questions as $question)
                <li wire:key="queue-{{ $question->id }}">
                    <x-overlay.question-card :question="$question" />
                </li>
            @endforeach
        </ol>
    @endif
</div>
