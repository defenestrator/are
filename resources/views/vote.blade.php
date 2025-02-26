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
                <flux:heading>Question Limit Reach</flux:heading>
            </div>
        @endif

        <div wire:poll.10s>
            <h2>Questions</h2>
            <ul>
                @foreach (Question::getSortedQuestions() as $question)
                    <li>{{ $question->question }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endvolt
</x-layouts.app>
