<?php

use Livewire\Volt\Component;
use App\Models\Question;
use Illuminate\Validation\ValidationException;

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
        @if (Auth::user()->canSubmitQuestion())
            <div>
                <form wire:submit="saveQuestion">
                    <flux:input wire:model="question" label="Question" description="Your Greatest Query" />
                    <flux:button type="submit">Submit</flux:button>
                </form>
            </div>
        @else
            <div>
                <flux:heading>Question Limit Reached</flux:heading>
            </div>
        @endif

        <div class="mt-6">
            <h2>Questions</h2>
            <ul>
                @foreach (Question::getSortedQuestions() as $question)
                    <li wire:key="{{ $question->id }}">
                        <livewire:question-card :question="$question" :vote-count="$question->votes" :key="$question->id" />
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    @endvolt
</x-layouts.app>
