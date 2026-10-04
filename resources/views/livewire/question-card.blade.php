<?php

use App\Exceptions\QuestionRejected;
use App\Models\Question;
use App\Moderation;
use App\QuestionQueue;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    public Question $question;
    public int $voteCount;
    // Shown next to the count so the browser can drop out-of-order VoteCasts.
    public int $voteVersion = 0;
    // The viewer's own vote on this question: 1, -1 or 0 for none. The page
    // passes just this card's vote, not the viewer's whole history (#101).
    public int $userVote = 0;

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
        $this->voteVersion = (int) $this->question->vote_version;
        $user = Auth::user();
        $canModerate ??= $user?->can('moderate') ?? false;

        $this->canEdit = $user !== null
            && ($user->id === $this->question->user_id || $canModerate);
        $this->showIdentities = $canModerate;
    }

    // Votes act on this card's own question and take no id from the browser
    // (#114). $question is a model property, so Livewire will not let the
    // browser change which question it is.
    public function upvote(): void
    {
        $this->vote(1);
    }

    public function downvote(): void
    {
        $this->vote(-1);
    }

    // The same rules as !vote in chat.
    private function vote(int $direction): void
    {
        if (! Auth::check()) {
            return;
        }

        try {
            $result = QuestionQueue::vote(Auth::user(), $this->question, $direction);
        } catch (QuestionRejected) {
            return;
        }

        $this->userVote = $direction;
        $this->voteCount = $result['votes'];
        $this->voteVersion = $result['version'];
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
            {{-- The number viewers vote with in chat: !vote <number> --}}
            <p class="bold text-lg my-2 py-2"><span class="mr-1 text-sm font-normal tabular-nums text-zinc-500 dark:text-zinc-400" title="Vote in chat with !vote {{ $question->id }}">#{{ $question->id }}</span> {{ $question->question }}</p>
            <div class="min-h-2"></div>

            <div class="flex jusify-between items-center">
                <div class="flex items-center mr-auto">
                    <flux:text class="w-4 max-w-4 min-w-4 text-sm mr-2 text-zinc-500 dark:text-zinc-400 tabular-nums" data-vote-count="{{ $question->id }}" data-vote-version="{{ $voteVersion }}">
                        {{ $voteCount }}</flux:text>

                    <div class="flex items-center gap-2">
                        <div>
                            <flux:button wire:click="upvote" aria-label="Upvote #{{ $question->id }}"
                                variant="{{ $userVote > 0 ? 'primary' : 'ghost' }}" size="sm"
                                class="flex items-center">
                                <flux:icon.hand-thumb-up name="hand-thumb-up" variant="outline"
                                    class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />
                            </flux:button>
                        </div>

                        <div>
                            <flux:button wire:click="downvote" aria-label="Downvote #{{ $question->id }}"
                                variant="{{ $userVote < 0 ? 'primary' : 'ghost' }}"
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
                    <flux:avatar circle :src="$question->user->avatar_url" :initials="$question->user->initials()" :alt="$question->user->name" />
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
