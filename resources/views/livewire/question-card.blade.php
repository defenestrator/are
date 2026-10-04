<?php

use App\Models\Question;
use App\Moderation;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    public Question $question;
    public int $voteCount;
    public array $userVotes;

    // Only decides whether the button shows; deleteQuestion() authorises itself.
    #[Locked]
    public bool $canEdit;

    // Moderators see every account the author has linked, to spot sockpuppets.
    #[Locked]
    public bool $showIdentities = false;

    // The vote page passes $canModerate, resolved once per render, so a full
    // queue does not run the moderator check once per card. It mirrors
    // QuestionPolicy::delete, which stays the authority.
    public function mount(?bool $canModerate = null) {
        $user = Auth::user();
        $canModerate ??= $user?->can('moderate') ?? false;

        $this->canEdit = $user !== null
            && ($user->id === $this->question->user_id || $canModerate);
        $this->showIdentities = $canModerate;
    }

    public function upvote(Question $question)
    {
        if (! $this->canVote($question)) {
            return;
        }

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
        if (! $this->canVote($question)) {
            return;
        }

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

    private function canVote(Question $question): bool
    {
        return Auth::check() && ! Auth::user()->isBanned() && $question->archived_at === null;
    }

    public function deleteQuestion()
    {
        // Moderators can remove any question (logged); authors can remove their own.
        $this->authorize('delete', $this->question);

        Moderation::deleteQuestion(Auth::user(), $this->question);

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

                @if ($canEdit)
                    <flux:button wire:click="deleteQuestion" wire:confirm="Delete this question?" variant="ghost" size="sm"
                        icon="trash" aria-label="Delete question" class="mr-1" />
                @endif

                <div class="flex items-center pt-2 gap-2 p-3">
                    <img src="{{ $question->user->twitch_avatar_url }}" size="xs" class="w-10 rounded-full" />
                    <div class="flex-row" variant="strong">
                        {{ $question->user->name }}
                        @if ($showIdentities)
                            <ul class="text-xs text-zinc-500 dark:text-zinc-400" aria-label="Linked accounts">
                                @foreach ($question->user->identities as $identity)
                                    <li wire:key="identity-{{ $identity->id }}">{{ $identity->provider->label() }}: {{ $identity->name ?? '?' }} ({{ $identity->provider_user_id }})</li>
                                @endforeach
                            </ul>
                        @endif
                </div>
                </div>

            </div>
        </div>
    </div>
</div>
