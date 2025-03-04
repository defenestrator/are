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
        <flux:card class="m-2 rounded-lg max-w-120 bg-zinc-400/5 dark:bg-zinc-900">
            <div class="pl-2">
                <flux:text class="mb-2" variant="strong">{{ $question->question }}</flux:text>

                <div class="flex jusify-between items-center">
                    <div class="flex items-center mr-auto">
                        <flux:text class="w-4 max-w-4 min-w-4 text-sm mr-2 text-zinc-500 dark:text-zinc-400 tabular-nums">
                            {{ $question->votes }}</flux:text>

                        <div class="flex items-center gap-2">
                            <flux:icon.hand-thumb-up name="hand-thumb-up" variant="outline"
                                class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />
                        </div>
                    </div>

                    <div class="flex items-center pt-2 gap-2">
                        <flux:avatar src="{{ $question->user->twitch_avatar_url }}" size="xs" class="shrink-0" />
                        <flux:subheading variant="strong">
                            {{ $question->user->name }}
                        </flux:subheading>
                    </div>

                </div>
            </div>
        </flux:card>
    @endvolt
    </body>
</html>

