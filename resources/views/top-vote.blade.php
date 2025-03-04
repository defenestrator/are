<?php

use App\Models\Question;
use Livewire\Volt\Component;

new class extends Component {
    public Question $question;

    public function mount() {
        $this->question = Question::getSortedQuestions(1)->first();
    }
} ?>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body>
    @volt
        <livewire:question-card :question="$question" :vote-count="$question->votes"/>
    @endvolt
    </body>
</html>

