<?php

use App\Enums\SongRequestStatus;
use App\Models\SongRequest;
use App\SongRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    // Livewire actions are public endpoints: each one authorises itself, and
    // App\SongRequests authorises again for any other caller.
    public function play(int $requestId): void
    {
        $this->authorize('moderate');

        try {
            SongRequests::play(auth()->user(), SongRequest::findOrFail($requestId));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['queue' => $e->getMessage()]);
        }
    }

    public function playNext(): void
    {
        $this->authorize('moderate');

        SongRequests::playNext(auth()->user());
    }

    public function finish(): void
    {
        $this->authorize('moderate');

        SongRequests::finish(auth()->user());
    }

    public function skip(int $requestId): void
    {
        $this->authorize('moderate');

        try {
            SongRequests::skip(auth()->user(), SongRequest::findOrFail($requestId));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['queue' => $e->getMessage()]);
        }
    }

    public function clear(): void
    {
        $this->authorize('moderate');

        SongRequests::clear(auth()->user());
    }

    public function with(): array
    {
        return [
            'nowPlaying' => SongRequests::nowPlaying(),
            'queued' => SongRequest::queued()->with('track')->get(),
            'recent' => SongRequest::with('track')
                ->whereIn('status', [SongRequestStatus::Played->value, SongRequestStatus::Skipped->value])
                ->latest('finished_at')->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<div class="space-y-10" wire:poll.10s>
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Song requests</flux:heading>
        <flux:link href="{{ route('music.catalogue') }}">Catalogue</flux:link>
    </div>

    <flux:error name="queue" />

    <section class="space-y-3">
        <flux:heading size="lg">Now playing</flux:heading>
        @if ($nowPlaying)
            <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <span class="flex-1">
                    <span class="font-medium">{{ $nowPlaying->track->title }}</span>
                    <span class="text-zinc-500">· {{ $nowPlaying->track->artist }} · requested by {{ $nowPlaying->requester_name }}</span>
                </span>
                <flux:button size="sm" variant="primary" wire:click="finish">Done</flux:button>
                <flux:button size="sm" wire:click="skip({{ $nowPlaying->id }})">Skip</flux:button>
            </div>
        @else
            <flux:text>Nothing is playing. The now-playing overlay is blank.</flux:text>
        @endif
    </section>

    <section class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="lg" class="flex-1">Queue ({{ $queued->count() }})</flux:heading>
            <flux:button size="sm" variant="primary" wire:click="playNext" :disabled="$queued->isEmpty()">Play next</flux:button>
            <flux:button size="sm" variant="danger" wire:click="clear" wire:confirm="Skip every queued request?" :disabled="$queued->isEmpty()">Clear queue</flux:button>
        </div>
        <ol class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($queued as $request)
                <li class="py-2 flex flex-wrap items-center gap-3" wire:key="sr-{{ $request->id }}">
                    <span class="tabular-nums text-zinc-500 w-8">{{ $loop->iteration }}</span>
                    <span class="flex-1">
                        <span class="font-medium">{{ $request->track->title }}</span>
                        <span class="text-zinc-500">· {{ $request->track->artist }}</span>
                    </span>
                    <span class="text-zinc-500">{{ $request->requester_name }}</span>
                    @if ($request->source === App\Enums\SongRequestSource::ChannelPoints)
                        <flux:badge size="sm" color="purple">Channel points</flux:badge>
                    @endif
                    <flux:button size="sm" wire:click="play({{ $request->id }})">Play</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="skip({{ $request->id }})">Skip</flux:button>
                </li>
            @empty
                <li class="py-2 text-zinc-500">No requests waiting. Viewers request with !song &lt;title or number&gt;.</li>
            @endforelse
        </ol>
    </section>

    <section class="space-y-3">
        <flux:heading size="lg">Recently finished</flux:heading>
        <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            @forelse ($recent as $request)
                <li class="py-2 flex gap-3" wire:key="srr-{{ $request->id }}">
                    <span class="flex-1">{{ $request->track->title }} <span class="text-zinc-500">· {{ $request->requester_name }}</span></span>
                    <span class="text-zinc-500">{{ $request->status === App\Enums\SongRequestStatus::Played ? 'played' : 'skipped' }}</span>
                </li>
            @empty
                <li class="py-2 text-zinc-500">Nothing yet.</li>
            @endforelse
        </ul>
    </section>
</div>
