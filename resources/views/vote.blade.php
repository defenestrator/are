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

        return $queue + ['userVotes' => $this->viewerVotesOn($queue['top']->modelKeys(), $queue['recent']->modelKeys())];
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
            QuestionQueue::submit(auth()->user(), $this->question);
        } catch (QuestionRejected $e) {
            throw ValidationException::withMessages([
                'question' => $e->getMessage(),
            ]);
        }

        $this->question = "";
    }

    // Resolved once per render and handed to every card.
    #[Computed]
    public function canModerate(): bool
    {
        return Gate::allows('moderate');
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
            @if (Auth::user()->canSubmitQuestion())
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

        <div class="mt-6 grid sm:grid-cols-2 gap-2" x-data="liveQueue">
            <div>
                <h2>Top Suggestions</h2>
                <ul x-ref="top">
                    @foreach ($top as $question)
                        <li wire:key="hot-li-{{ $question->id }}" data-question-id="{{ $question->id }}">
                            <livewire:question-card @question-deleted="$refresh" :user-vote="$userVotes[$question->id] ?? 0" :question="$question" :vote-count="$question->votes" :can-moderate="$this->canModerate" :key="'hot-'.$question->id.'-v'.$question->vote_version" />
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2>New Ideas</h2>
                <ul>
                    @foreach ($recent as $question)
                        <li wire:key="recent-li-{{ $question->id }}" data-question-id="{{ $question->id }}">
                            <livewire:question-card @question-deleted="$refresh" :user-vote="$userVotes[$question->id] ?? 0" :question="$question" :vote-count="$question->votes" :can-moderate="$this->canModerate" :key="'recent-'.$question->id.'-v'.$question->vote_version" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
