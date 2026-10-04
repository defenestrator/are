<?php

use App\Models\ModerationAction;
use App\Models\Question;
use App\Models\User;
use App\Models\UserBan;
use App\Moderation;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $search = '';
    public ?int $duplicateId = null;
    public ?int $targetId = null;
    public string $duration = '10';
    public string $reason = '';

    // Livewire actions are public endpoints: each one authorises itself, and
    // App\Moderation authorises again for any other caller.
    public function merge(): void
    {
        $this->authorize('moderate');

        $this->validate([
            'duplicateId' => 'required|integer|exists:questions,id',
            'targetId' => 'required|integer|exists:questions,id',
        ]);

        $duplicate = Question::findOrFail($this->duplicateId);
        $this->authorize('merge', $duplicate);

        try {
            Moderation::mergeQuestions(auth()->user(), $duplicate, Question::findOrFail($this->targetId));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['duplicateId' => $e->getMessage()]);
        }

        $this->reset('duplicateId', 'targetId');
    }

    public function ban(int $userId): void
    {
        $target = User::findOrFail($userId);
        $this->authorize('ban', $target);

        $this->validate([
            'duration' => 'required|in:10,60,1440,10080,permanent',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            Moderation::ban(
                auth()->user(),
                $target,
                $this->duration === 'permanent' ? null : (int) $this->duration,
                $this->reason ?: null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $this->reset('reason');
    }

    public function unban(int $userId): void
    {
        $target = User::findOrFail($userId);
        $this->authorize('unban', $target);

        Moderation::unban(auth()->user(), $target);
    }

    public function with(): array
    {
        $term = trim($this->search);

        return [
            'questions' => Question::getSortedQuestions(),
            'users' => $term === ''
                ? collect()
                : User::where('name', 'like', '%' . addcslashes($term, '%_\\') . '%')->orderBy('name')->limit(20)->get(),
            'bans' => UserBan::inEffect()->with('user', 'moderator')->latest('id')->get(),
            'actions' => ModerationAction::with('moderator')->latest('id')->limit(50)->get(),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('moderation')
    <div class="space-y-10">
        <flux:heading size="xl" level="1">Moderation</flux:heading>

        <section class="space-y-3">
            <flux:heading size="lg">Merge duplicate questions</flux:heading>
            <flux:text>Votes move to the kept question, except from people who already voted on it. The duplicate is deleted.</flux:text>
            <form wire:submit="merge" class="flex flex-wrap items-end gap-2">
                <flux:input type="number" wire:model="duplicateId" label="Duplicate #" class="max-w-40" />
                <flux:input type="number" wire:model="targetId" label="Keep #" class="max-w-40" />
                <flux:button type="submit">Merge</flux:button>
            </form>
            <flux:error name="duplicateId" />
            <flux:error name="targetId" />
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($questions as $question)
                    <li class="py-2 flex gap-3" wire:key="mq-{{ $question->id }}">
                        <span class="tabular-nums text-zinc-500 w-12">#{{ $question->id }}</span>
                        <span class="tabular-nums w-10">{{ $question->votes }}</span>
                        <span class="flex-1">{{ $question->question }}</span>
                        <span class="text-zinc-500">{{ $question->user?->name }}</span>
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">The queue is empty.</li>
                @endforelse
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Ban or time out a user</flux:heading>
            <flux:text>Applies however they signed in. Twitch bans still come from Twitch; these are in addition.</flux:text>
            <div class="flex flex-wrap items-end gap-2">
                <flux:input wire:model.live.debounce.300ms="search" label="Find a user" placeholder="Name" class="max-w-xs" />
                <flux:select wire:model="duration" label="Length" class="max-w-40">
                    <flux:select.option value="10">10 minutes</flux:select.option>
                    <flux:select.option value="60">1 hour</flux:select.option>
                    <flux:select.option value="1440">1 day</flux:select.option>
                    <flux:select.option value="10080">1 week</flux:select.option>
                    <flux:select.option value="permanent">Permanent</flux:select.option>
                </flux:select>
                <flux:input wire:model="reason" label="Reason (optional)" class="max-w-sm" />
            </div>
            <flux:error name="reason" />
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($users as $user)
                    <li class="py-2 flex items-center gap-3" wire:key="mu-{{ $user->id }}">
                        <span class="flex-1">{{ $user->name }}</span>
                        @if ($user->isTwitchBanned())
                            <flux:badge color="red">Banned on Twitch</flux:badge>
                        @endif
                        @if ($user->isLocallyBanned())
                            <flux:badge color="red">Banned here</flux:badge>
                            @can('unban', $user)
                                <flux:button size="sm" wire:click="unban({{ $user->id }})">Lift local ban</flux:button>
                            @endcan
                        @elseif (auth()->user()->can('ban', $user))
                            <flux:button size="sm" variant="danger" wire:click="ban({{ $user->id }})" wire:confirm="Ban {{ $user->name }}?">Ban</flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Local bans in effect</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($bans as $ban)
                    <li class="py-2 flex items-center gap-3" wire:key="mb-{{ $ban->id }}">
                        <span class="w-40">{{ $ban->user->name }}</span>
                        <span class="w-48 text-zinc-500">{{ $ban->ends_at ? 'until '.$ban->ends_at->toDateTimeString() : 'permanent' }}</span>
                        <span class="w-32 text-zinc-500">by {{ $ban->moderator?->name ?? 'deleted user' }}</span>
                        <span class="flex-1 text-zinc-500">{{ $ban->reason }}</span>
                        @can('unban', $ban->user)
                            <flux:button size="sm" wire:click="unban({{ $ban->user_id }})">Lift</flux:button>
                        @endcan
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">Nobody is banned here.</li>
                @endforelse
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Audit log</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($actions as $action)
                    <li class="py-2 flex gap-3" wire:key="ma-{{ $action->id }}">
                        <span class="text-zinc-500 w-40 tabular-nums">{{ $action->created_at->toDateTimeString() }}</span>
                        <span class="w-32">{{ $action->moderator?->name ?? 'deleted user' }}</span>
                        <span class="w-36 font-mono">{{ $action->action }}</span>
                        <span class="flex-1 text-zinc-500 break-all">{{ $action->details ? json_encode($action->details) : '' }}</span>
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">No moderator actions yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
    @endvolt
</x-layouts.app>
