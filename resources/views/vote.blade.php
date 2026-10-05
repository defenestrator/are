<?php

use App\Events\QuestionArchived;
use Livewire\Volt\Component;
use App\Exceptions\QuestionRejected;
use App\Models\Question;
use App\QuestionQueue;
use Illuminate\Validation\ValidationException;
use App\Models\Topic;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use App\Moderation;

new class extends Component {
    public $question = "";

    // Live updates are handled in the browser (resources/js/live-queue.js): the
    // busy `questions` channel without a server request per vote, and topic
    // changes with one jittered refresh. There are no Livewire echo listeners,
    // which would log "Laravel Echo cannot be found" on every load without
    // Reverb (#125).

    /**
     * @return array{top: \Illuminate\Database\Eloquent\Collection<int, Question>, recent: \Illuminate\Database\Eloquent\Collection<int, Question>, userVotes: array<int, int>}
     */
    public function with(): array
    {
        $queue = Question::cachedQueue();
        $this->renderedState = $this->pageState();

        return $queue + ['userVotes' => $this->viewerVotesOn($queue['top']->modelKeys(), $queue['recent']->modelKeys())];
    }

    // What the last render showed (#180): the queue's version, the topic and
    // the viewer's submit form.
    #[Locked]
    public string $renderedState = '';

    private function pageState(): string
    {
        $user = auth()->user();
        $topic = Topic::current();

        return sha1(json_encode([
            Question::queueVersion(),
            $topic?->id,
            $topic?->topic,
            $this->canSubmit,
            $user?->isBanned(),
        ]));
    }

    /**
     * What live-queue.js calls to catch up (fallback polls, a new question,
     * a reconnect) instead of $refresh. With the cards now Blade (#180), a
     * render costs every card, so a poll that would show nothing new renders
     * nothing: most polls cost a few queries instead of 100 cards.
     */
    public function refreshQueue(): void
    {
        if (hash_equals($this->renderedState, $this->pageState())) {
            $this->skipRender();
        }
    }

    /**
     * The viewer's own votes on the questions this render shows, keyed by
     * question id. Read on every render: a card whose total changed is
     * remounted (its key carries vote_version), and must show their vote as it
     * is now, not as it was when the page loaded. Only on-page questions are
     * read, and each card is handed only its own vote, so the page does not
     * grow with every vote the viewer has ever cast (#101).
     *
     * @param  list<int>  ...$questionIds
     * @return array<int, int>
     */
    private function viewerVotesOn(array ...$questionIds): array
    {
        $ids = array_values(array_unique(array_merge(...$questionIds)));
        if ($ids === []) {
            return [];
        }

        return auth()->user()->votes()
            ->whereIn('question_id', $ids)
            ->pluck('count', 'question_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function saveQuestion()
    {
        $this->validate([
            'question' => QuestionQueue::QUESTION_RULES,
        ]);

        // The same rules as !q in chat.
        try {
            QuestionQueue::submit(auth()->user(), $this->question, \App\Models\Question::SOURCE_WEB);
        } catch (QuestionRejected $e) {
            throw ValidationException::withMessages([
                'question' => $e->getMessage(),
            ]);
        }

        $this->question = "";
    }

    // Asked once per request: by the submit form and by pageState().
    #[Computed]
    public function canSubmit(): bool
    {
        return (bool) auth()->user()?->canSubmitQuestion();
    }

    // Resolved once per render and handed to every card.
    #[Computed]
    public function canModerate(): bool
    {
        return Gate::allows('moderate');
    }

    // --- Card actions (#180) -------------------------------------------------
    //
    // Cards are Blade components, so their buttons call these with the
    // question id. The id comes from the browser, so it is only ever looked
    // up among open questions (#114): a closed, deleted or made-up id does
    // nothing. Every rule then runs exactly as before: QuestionQueue::vote
    // (bans, closed questions, Question::recordVote) and the delete policy.

    private function openQuestion(mixed $id): ?Question
    {
        return is_numeric($id) ? Question::active()->whereKey((int) $id)->first() : null;
    }

    /**
     * Renderless: a vote doesn't re-render the 100 cards. live-queue.js writes
     * the new total (by version) and the viewer's pressed button from the
     * vote-recorded event; everyone else gets the VoteCast broadcast.
     */
    #[Renderless]
    public function upvote(mixed $id): void
    {
        $this->vote($id, 1);
    }

    #[Renderless]
    public function downvote(mixed $id): void
    {
        $this->vote($id, -1);
    }

    // The same rules as !vote in chat.
    private function vote(mixed $id, int $direction): void
    {
        $question = $this->openQuestion($id);

        if ($question === null || ! auth()->check()) {
            return;
        }

        try {
            $result = QuestionQueue::vote(auth()->user(), $question, $direction);
        } catch (QuestionRejected) {
            return;
        }

        $this->dispatch('vote-recorded', question_id: $question->id, votes: $result['votes'], version: $result['version'], vote: $direction);
    }

    public function deleteQuestion(mixed $id): void
    {
        $question = $this->openQuestion($id);

        if ($question === null) {
            return;
        }

        // Moderators can remove any question (logged); authors can remove their own.
        $this->authorize('delete', $question);

        Moderation::deleteQuestion(auth()->user(), $question);
    }

    public function clearUserQuestion() {
        $questions = auth()->user()->questions()->active();
        $ids = $questions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $questions->delete();

        if ($ids !== []) {
            QuestionArchived::dispatch($ids);
        }
    }

} ?>

<x-layouts.app>

    @volt('vote')
    <div>
        <livewire:topic @topic-changed="$refresh" />
        <div class="mt-4">
            @if ($this->canSubmit)
            <form wire:submit="saveQuestion">
                <flux:input.group>
                    <flux:input wire:model="question" placeholder="What should I sing about?" />
                    <flux:button type="submit" class="cursor-pointer">Submit</flux:button>
                </flux:input.group>
            </form>
            @else
            @if (Auth::user()->isBanned())
                <flux:heading>You are banned or timed out in this channel</flux:heading>
            @elseif (Topic::current())
                <div class="items-center flex gap-2">
                    <flux:heading>Question Limit Reached</flux:heading>
                    <flux:button wire:click="clearUserQuestion" variant="danger" size="xs" inset="left" class="ml-1 flex items-center gap-2 cursor-pointer" :loading="false">
                        <flux:icon.x-mark name="xmark" variant="outline" class="size-4 text-white [&_path]:stroke-[2.25]" />
                    </flux:button>
                </div>
            @else
                <flux:heading>There is no topic</flux:heading>
            @endif
        @endif
        </div>

        {{-- The icons every card repeats, once (#180). --}}
        <x-vote-icons />

        {{-- Cards are Blade components (#180). Each <li> is keyed by its
             vote_version, so a card whose total changed is rendered afresh. --}}
        <div class="mt-6 grid sm:grid-cols-2 gap-2" x-data="liveQueue" x-on:vote-recorded.window="recorded($event.detail)">
            <div>
                <h2>Top Suggestions</h2>
                <ul x-ref="top">
                    @foreach ($top as $question)
                        <li wire:key="hot-li-{{ $question->id }}-v{{ (int) $question->vote_version }}" data-question-id="{{ $question->id }}">
                            <x-question-card :question="$question" :user-vote="$userVotes[$question->id] ?? 0" :can-moderate="$this->canModerate" />
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2>New Ideas</h2>
                <ul>
                    @foreach ($recent as $question)
                        <li wire:key="recent-li-{{ $question->id }}-v{{ (int) $question->vote_version }}" data-question-id="{{ $question->id }}">
                            <x-question-card :question="$question" :user-vote="$userVotes[$question->id] ?? 0" :can-moderate="$this->canModerate" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
