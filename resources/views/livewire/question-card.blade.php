<?php

use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public Question $question;
    public int $voteCount;

    public function upvote(Question $question) {
        DB::table('question_votes')->updateOrInsert([
            'question_id' => $question->id,
            'user_id' => Auth::user()->id,
        ], [
            'count' => 1,
        ]);

        $this->voteCount = $this->question->voteCount();
    }

    public function downvote(Question $question) {
        DB::table('question_votes')->updateOrInsert([
            'question_id' => $question->id,
            'user_id' => Auth::user()->id,
        ], [
            'count' => -1,
        ]);

        $this->voteCount = $this->question->voteCount();
    }
} ?>

<div class="p-3 sm:p-4 rounded-lg">
    <div class="flex flex-row sm:items-center gap-2">
        <flux:avatar src="{{ $question->user->twitch_avatar_url }}" size="xs" class="shrink-0" />

        <div class="flex flex-col gap-0.5 sm:gap-2 sm:flex-row sm:items-center">
            <div class="flex items-center gap-2">
                <flux:heading>{{ $question->user->name }}</flux:heading>
                </div>
            </div>
        </div>

        <div class="min-h-2 sm:min-h-1"></div>

        <div class="pl-8">
            <flux:text variant="strong">{{ $question->question }}</flux:text>

            <div class="min-h-2"></div>

            <div class="flex items-center gap-0">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $voteCount }}</flux:text>

                <flux:button wire:click="upvote({{ $question->id }})" variant="ghost" size="sm" inset="left" class="ml-1 flex items-center gap-2" :loading="false">
                    <flux:icon.hand-thumb-up name="hand-thumb-up" variant="outline" class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />
                </flux:button>

                <flux:button wire:click="downvote({{ $question->id }})" variant="ghost" size="sm" inset="left" class="ml-1 flex items-center gap-2" :loading="false">
                    <flux:icon.hand-thumb-down name="hand-thumb-down" variant="outline" class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />
                </flux:button>

                <flux:dropdown>
                    <flux:button icon="ellipsis-horizontal" variant="subtle" size="sm" />

                    <flux:menu class="min-w-0">
                        <flux:menu.item icon="pencil-square">Edit</flux:menu.item>
                        <flux:menu.item variant="danger" icon="trash">Delete</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
        </div>
    </div>
</div>

