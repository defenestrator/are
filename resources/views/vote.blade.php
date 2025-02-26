<?php

use Livewire\Volt\Component;
use App\Models\Question;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $question = "";

    public function saveQuestion()
    {
        $this->validate([
            'question' => 'required|min:10|max:420',
        ]);

        // TODO: Make this prettier
        if (!auth()->user()->canSubmitQuestion()) {
            throw ValidationException::withMessages([
                'question' => 'You have reached the question limit',
            ]);
        }

        auth()->user()->questions()->create([
            'question' => $this->question,
        ]);

        $this->question = "";
    }

} ?>

<x-layouts.app>
    @volt
    <div>
        <livewire:topic @topic-changed="$refresh" />

        <div class="mt-2" wire:poll.10s>
        @if (Auth::user()->canSubmitQuestion())
                <form wire:submit="saveQuestion">
                    <flux:input wire:model="question" label="Question" description="Your Greatest Query" />
                    <flux:button type="submit">Submit</flux:button>
                </form>
        @else
                @if (DB::table("topics")->count() > 0)
                    <flux:heading>Question Limit Reached</flux:heading>
                @else
                    <flux:heading>There is no topic</flux:heading>
                @endif
        @endif
        </div>

        <div class="mt-6 grid grid-cols-2 gap-2">
            <div wire:poll.30s>
                <h2>Hot Questions</h2>
                <ul>
                    @foreach (Question::getSortedQuestions() as $question)
                        <li wire:key="hot-li-{{ $question->id }}">
                            <livewire:question-card @question-deleted="$refresh" :question="$question" :vote-count="$question->votes" :key="'hot-'.$question->id" />
                        </li>
                    @endforeach
                </ul>
            </div>

            <div wire:poll.30s>
                <h2>Latest Questions</h2>
                <ul>
                    @foreach (Question::getRecentQuestions() as $question)
                        <li wire:key="recent-li-{{ $question->id }}">
                            <livewire:question-card @question-deleted="$refresh" :question="$question" :vote-count="$question->votes" :key="'recent-'.$question->id" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
