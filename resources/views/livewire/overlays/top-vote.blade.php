<?php

use App\Enums\Overlay;
use App\Livewire\Overlays\PollingOverlay;
use App\Models\Question;

/*
 * The single leading question. This was the standalone /top-vote page. Kept
 * live by resources/js/live-overlay.js, as a ranked slice of one.
 */
new class extends PollingOverlay {
    protected function overlay(): Overlay
    {
        return Overlay::TopVote;
    }

    public function with(): array
    {
        $current = $this->tokenIsCurrent();

        return [
            'current' => $current,
            'questions' => $current ? Question::cachedQueue()['top']->take(1) : collect(),
        ];
    }
}; ?>

<div class="absolute left-16 top-16 w-[960px] vertical:inset-x-14 vertical:top-[200px] vertical:w-auto">
    <div x-data="liveOverlay" data-live="{{ $current ? 'on' : 'off' }}" data-live-mode="top" data-live-limit="1">
        @if ($questions->isEmpty())
            <x-overlay.empty message="No questions to vote on yet." />
        @else
            <p class="overlay-enter mb-4 flex items-center gap-3 text-lg font-semibold uppercase tracking-[0.2em] text-white/80 [text-shadow:0_2px_8px_rgb(0_0_0/0.6)] vertical:text-2xl">
                <span class="size-2.5 rounded-full bg-[#7c5cff] vertical:size-3.5"></span>
                Top vote
            </p>
        @endif

        <ol x-ref="list">
            @foreach ($questions as $question)
                <li wire:key="top-vote-{{ $question->id }}" data-question-id="{{ $question->id }}">
                    <x-overlay.question-card :question="$question" featured />
                </li>
            @endforeach
        </ol>
    </div>
</div>
