<?php

use App\Models\Question;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public Question $question;
    public int $voteCount;
    public string $questionBeingEdited;

    public function mount()
    {
        $this->questionBeingEdited = $this->question->question;
    }

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

    public function updateQuestion()
    {
        if (strlen($this->questionBeingEdited) > 500) {
            return;
        }

        if ($this->question->user_id !== Auth::user()->id) {
            return;
        }

        $this->question->question = $this->questionBeingEdited;
        $this->question->save();

        Flux::modals()->close();
    }

    public function deleteQuestion()
    {
        if (! Auth::user()->isAdminUser()) {
            return;
        }

        $this->question->delete();

        $this->dispatch('question-deleted');
    }
} ?>

<div>
    <flux:card class="m-2 rounded-lg max-w-120 bg-zinc-400/5 dark:bg-zinc-900">
        <div class="pl-2">
            <flux:text variant="strong">{{ $question->question }}</flux:text>
            <div class="min-h-2"></div>

            <div class="flex jusify-between items-center">
                <div class="flex items-center mr-auto">
                    <flux:text class="text-sm mr-2 text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $voteCount }}</flux:text>

                    <div class="flex items-center gap-2">
                        <div>
                        <flux:button
                                wire:click="upvote({{ $question->id }})"
                                variant="ghost"
                                size="sm"
                                class="flex items-center"
                                :loading="false">
                            <flux:icon.hand-thumb-up name="hand-thumb-up" variant="outline" class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />
                        </flux:button>
                        </div>

<div>
                        <flux:button
                                wire:click="downvote({{ $question->id }})"
                                variant="ghost"
                                size="sm"
                                class="flex items-center"
                                :loading="false">
                            <flux:icon.hand-thumb-down name="hand-thumb-down" variant="outline" class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />

                        </flux:button>
                        </div>

                        @if (Auth::user()->isAdminUser())
                            <flux:button wire:click="deleteQuestion()" variant="danger" size="sm" inset="left" class="ml-1 flex items-center gap-2 cursor-pointer" :loading="false">
                                <flux:icon.x-mark name="xmark" variant="outline" class="size-4 text-white [&_path]:stroke-[2.25]" />
                            </flux:button>
                        @endif
                    </div>
                </div>

                <div class="flex items-center pt-2">
                    <flux:avatar src="{{ $question->user->twitch_avatar_url }}" size="xs" class="shrink-0" />
                    <flux:subheading variant="strong" class="ml-2">
                        {{ $question->user->name }}
                    </flux:subheading>
                </div>

            </div>
        </div>
    </flux:card>

    @if (Auth::user()->id == $question->user_id)
    <flux:dropdown>
        <flux:button icon="ellipsis-horizontal" variant="subtle" size="sm" />

        <flux:menu class="min-w-0">
            @if (Auth::user()->id === $question->user_id)
                <flux:modal.trigger name="edit-question-{{ $question->id }}">
                    <flux:menu.item icon="pencil-square">Edit</flux:menu.item>
                </flux:modal.trigger>
            @endif

            <flux:menu.item variant="danger" icon="trash">Delete</flux:menu.item>
        </flux:menu>
    </flux:dropdown>

    <flux:modal name="edit-question-{{ $question->id }}" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Edit Question</flux:heading>
            </div>

            <flux:input label="Question" wire:model="questionBeingEdited" :value="$question->question" />

            <div class="flex">
                <flux:spacer />

                <flux:button type="submit" variant="primary" wire:click="updateQuestion">Save</flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</div>
