{{--
    A question card on /vote. A Blade component, not a Livewire one (#180):
    with a full queue that was 100 child components, each mounted and
    snapshotted on every page load. The buttons call the vote page's own
    actions with the question id (upvote, downvote, deleteQuestion), which
    look the question up as open and apply the same rules as before.

    live-queue.js writes VoteCast totals into [data-vote-count] when their
    version is newer than [data-vote-version]. The page keys each <li> by
    vote_version, so a card whose total changed is re-rendered fresh.

    Icons come from the <x-vote-icons /> sprite on the page.

    @props question     from Question::cachedQueue(), with `votes` and user.identities
    @props userVote     the viewer's vote on it: 1, -1 or 0
    @props canModerate  resolved once per page render
--}}
@props([
    'question',
    'userVote' => 0,
    'canModerate' => false,
])

@php
    $viewer = auth()->user();
    // Only decides whether the button shows; deleteQuestion() authorises itself.
    $canEdit = $viewer !== null && ($viewer->id === $question->user_id || $canModerate);
    $id = $question->id;
@endphp

<div class="card m-2 rounded-lg max-w-120 bg-zinc-400/5 dark:bg-zinc-900">
    <div class="pl-2">
        {{-- The number viewers vote with in chat: !vote <number> --}}
        <p class="bold text-lg my-2 py-2"><span class="mr-1 text-sm font-normal tabular-nums text-zinc-500 dark:text-zinc-400" title="Vote in chat with !vote {{ $id }}">#{{ $id }}</span> {{ $question->question }}</p>
        <div class="min-h-2"></div>

        <div class="flex items-center">
            <div class="flex items-center mr-auto">
                <span class="w-4 min-w-4 text-sm mr-2 text-zinc-500 dark:text-zinc-400 tabular-nums" data-vote-count="{{ $id }}" data-vote-version="{{ (int) $question->vote_version }}">{{ (int) $question->votes }}</span>

                <div class="flex items-center gap-2">
                    <button type="button" wire:click="upvote({{ $id }})" wire:loading.attr="disabled" wire:target="upvote({{ $id }})"
                        data-vote-button="up" data-question="{{ $id }}"
                        aria-label="Upvote #{{ $id }}" aria-pressed="{{ $userVote > 0 ? 'true' : 'false' }}" class="card-btn card-vote">
                        <svg class="size-4 shrink-0 text-zinc-400" aria-hidden="true"><use href="#icon-thumb-up"/></svg>
                    </button>

                    <button type="button" wire:click="downvote({{ $id }})" wire:loading.attr="disabled" wire:target="downvote({{ $id }})"
                        data-vote-button="down" data-question="{{ $id }}"
                        aria-label="Downvote #{{ $id }}" aria-pressed="{{ $userVote < 0 ? 'true' : 'false' }}" class="card-btn card-vote">
                        <svg class="size-4 shrink-0 text-zinc-400" aria-hidden="true"><use href="#icon-thumb-down"/></svg>
                    </button>
                </div>
            </div>

            @if ($canEdit)
                <button type="button" wire:click="deleteQuestion({{ $id }})" wire:confirm="Delete this question?" wire:loading.attr="disabled" wire:target="deleteQuestion({{ $id }})"
                    aria-label="Delete question" class="card-btn w-8 mr-1">
                    <svg class="size-5 shrink-0" aria-hidden="true"><use href="#icon-trash"/></svg>
                </button>
            @endif

            <div class="flex items-center pt-2 gap-2 p-3">
                @if ($question->user->avatar_url)
                    <img src="{{ $question->user->avatar_url }}" alt="{{ $question->user->name }}" class="size-10 shrink-0 rounded-full object-cover" loading="lazy" />
                @else
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-medium text-zinc-800 dark:bg-zinc-600 dark:text-white" aria-hidden="true">{{ $question->user->initials() }}</span>
                @endif
                <div>
                    {{ $question->user->name }}
                    @if ($canModerate)
                        {{-- Moderators see every account the author has linked, to spot sockpuppets. --}}
                        <ul class="text-xs text-zinc-500 dark:text-zinc-400" aria-label="Linked accounts">
                            @foreach ($question->user->identities as $identity)
                                <li>{{ $identity->provider->label() }}: {{ $identity->name ?? '?' }} ({{ $identity->provider_user_id }})</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
