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

    /**
     * A topic change is rare and alters the submit form, so it re-renders the
     * page. The busy `questions` channel is handled in the browser instead (see
     * resources/js/live-queue.js): votes and removals never cost the server a
     * request, and new questions trigger one jittered refresh.
     */
    public function getListeners(): array
    {
        return [
            'echo:topic,TopicChanged' => '$refresh',
        ];
    }

    /**
     * @return array{top: \Illuminate\Database\Eloquent\Collection<int, Question>, recent: \Illuminate\Database\Eloquent\Collection<int, Question>}
     */
    public function with(): array
    {
        return Question::cachedQueue();
    }

    /**
     * The viewer's own votes, read on every render: a card whose total changed
     * is remounted (its key carries vote_version), and must show their vote as
     * it is now, not as it was when the page loaded.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function userVotes(): array
    {
        return auth()->user()->votes()->get()
            ->mapWithKeys(fn($vote) => [$vote->question_id => $vote->count])
            ->toArray();
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
                            <livewire:question-card @question-deleted="$refresh" :user-votes="$this->userVotes" :question="$question" :vote-count="$question->votes" :can-moderate="$this->canModerate" :key="'hot-'.$question->id.'-v'.$question->vote_version" />
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2>New Ideas</h2>
                <ul>
                    @foreach ($recent as $question)
                        <li wire:key="recent-li-{{ $question->id }}" data-question-id="{{ $question->id }}">
                            <livewire:question-card @question-deleted="$refresh" :user-votes="$this->userVotes" :question="$question" :vote-count="$question->votes" :can-moderate="$this->canModerate" :key="'recent-'.$question->id.'-v'.$question->vote_version" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
