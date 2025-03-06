<?php

use App\Models\Question;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    public Question $question;
    public int $voteCount;
    public array $userVotes;
    public bool $canEdit;

    public function mount() {
        if (Auth::user() == null) {
            $this->canEdit = false;
        } else {
            $this->canEdit = Auth::user()->isAdminUser() || Auth::user()->id === $this->question->user_id;
        }
    }

    public function upvote(Question $question)
    {
        DB::table('question_votes')->updateOrInsert(
            [
                'question_id' => $question->id,
                'user_id' => Auth::user()->id,
            ],
            [
                'count' => 1,
            ],
        );

        $this->voteCount = $this->question->voteCount();
        $this->userVotes[$question->id] = 1;
    }

    public function downvote(Question $question)
    {
        DB::table('question_votes')->updateOrInsert(
            [
                'question_id' => $question->id,
                'user_id' => Auth::user()->id,
            ],
            [
                'count' => -1,
            ],
        );

        $this->voteCount = $this->question->voteCount();
        $this->userVotes[$question->id] = -1;
    }

    public function deleteQuestion()
    {
        if (!Auth::user()->isAdminUser()) {
            return;
        }

        $this->question->delete();

        $this->dispatch('question-deleted');
    }
}; ?>

<div>
    <div class="card m-2 rounded-lg max-w-120 bg-zinc-400/5 dark:bg-zinc-900">
        <div class="pl-2">
            <p class="bold text-lg my-2 py-2">{{ $question->question }}</p>
            <div class="min-h-2"></div>

            <div class="flex jusify-between items-center">
                <div class="flex items-center mr-auto">
                    <flux:text class="w-4 max-w-4 min-w-4 text-sm mr-2 text-zinc-500 dark:text-zinc-400 tabular-nums">
                        {{ $voteCount }}</flux:text>

                    <div class="flex items-center gap-2">
                        <div>
                            <flux:button wire:click="upvote({{ $question->id }})"
                                variant="{{ ($userVotes[$question->id] ?? 0) > 0 ? 'primary' : 'ghost' }}" size="sm"
                                class="flex items-center">
                                <flux:icon.hand-thumb-up name="hand-thumb-up" variant="outline"
                                    class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />
                            </flux:button>
                        </div>

                        <div>
                            <flux:button wire:click="downvote({{ $question->id }})"
                                variant="{{ ($userVotes[$question->id] ?? 0) < 0 ? 'primary' : 'ghost' }}"
                                size="sm" class="flex items-center">
                                <flux:icon.hand-thumb-down name="hand-thumb-down" variant="outline"
                                    class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />

                            </flux:button>
                        </div>


                    </div>
                </div>

                <div class="flex items-center pt-2 gap-2 p-3">
                    <img src="{{ $question->user->twitch_avatar_url }}" size="xs" class="w-10 rounded-full" />
                    <div class="flex-row" variant="strong">
                        {{ $question->user->name }}
                </div>
                </div>

            </div>
        </div>
    </div>
</div>
