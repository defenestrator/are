<?php

use App\ControlBus\BallotStatus;
use App\ControlBus\BusState;
use App\ControlBus\ControlBus;
use App\ControlBus\Game;
use App\ControlBus\Mode;
use App\Models\BusBallot;
use App\Models\BusControl;
use App\Models\BusPublication;
use App\Models\BusWindow;
use Livewire\Volt\Component;

new class extends Component {
    public string $reason = '';

    // Every action is a public endpoint: each authorises itself here, and
    // ControlBus authorises again for any other caller.
    public function kill(ControlBus $bus): void
    {
        $this->authorize('moderate');
        $bus->kill(auth()->user(), $this->reason !== '' ? $this->reason : null);
        $this->reset('reason');
    }

    public function restore(ControlBus $bus): void
    {
        $this->authorize('restoreBus');
        $bus->restore(auth()->user());
    }

    public function setGame(ControlBus $bus, string $game = ''): void
    {
        $this->authorize('moderate');
        $bus->setActiveGame(auth()->user(), $game === '' ? null : $game);
    }

    public function setMode(ControlBus $bus, string $game, string $mode): void
    {
        $this->authorize('moderate');
        $bus->setMode(auth()->user(), $game, Mode::from($mode));
    }

    public function pause(ControlBus $bus, string $game): void
    {
        $this->authorize('moderate');
        $bus->pause(auth()->user(), $game);
    }

    public function resume(ControlBus $bus, string $game): void
    {
        $this->authorize('moderate');
        $bus->resume(auth()->user(), $game);
    }

    public function vetoOption(ControlBus $bus, int $windowId, string $actionKey): void
    {
        $this->authorize('moderate');
        $bus->vetoOption(auth()->user(), BusWindow::findOrFail($windowId), $actionKey);
    }

    public function vetoPublication(ControlBus $bus, int $publicationId): void
    {
        $this->authorize('moderate');
        $bus->vetoPublication(auth()->user(), BusPublication::findOrFail($publicationId));
    }

    public function with(): array
    {
        $global = BusControl::find(BusControl::GLOBAL);

        $games = collect(Game::all())->map(function (Game $game) {
            $window = BusWindow::open()->where('game', $game->key)->latest('id')->first();

            $options = $window === null ? collect() : $window->ballots()
                ->whereNotNull('option_number')
                ->orderBy('option_number')
                ->orderBy('id')
                ->get()
                ->groupBy('option_number')
                ->map(fn ($ballots) => [
                    'number' => $ballots->first()->option_number,
                    'key' => $ballots->first()->action_key,
                    'label' => trim($ballots->first()->verb.' '.$ballots->first()->argument),
                    'votes' => $ballots->where('status', BallotStatus::Counted)->count(),
                    'vetoed' => $ballots->contains('status', BallotStatus::Vetoed),
                    'subscriber' => $ballots->first()->subscriber,
                ])
                ->values();

            return ['game' => $game, 'state' => BusState::read($game), 'window' => $window, 'options' => $options];
        });

        return [
            'global' => $global,
            'killed' => ! config('bus.enabled') || $global?->killed_at !== null,
            'disabled' => ! config('bus.enabled'),
            'games' => $games,
            'publications' => BusPublication::latest('id')->limit(20)->get(),
            'ballots' => BusBallot::with('user')->latest('id')->limit(40)->get(),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('bus')
    {{-- A moderator tool, not the viewer page: polling here is cheap and keeps the tally current. --}}
    <div class="space-y-10" wire:poll.5s>
        <flux:heading size="xl" level="1">Chat Control Bus</flux:heading>

        <section class="space-y-3">
            @if ($killed)
                <flux:callout variant="danger" icon="no-symbol" heading="Kill switch is on: nothing is published for any game.">
                    @if ($disabled)
                        <flux:callout.text>BUS_ENABLED is false in this deployment.</flux:callout.text>
                    @elseif ($global?->killedBy)
                        <flux:callout.text>Thrown by {{ $global->killedBy->name }} at {{ $global->killed_at->toDateTimeString() }}.</flux:callout.text>
                    @endif
                </flux:callout>
                @can('restoreBus')
                    @unless ($disabled)
                        <flux:button wire:click="restore" wire:confirm="Start the bus again?">Reset kill switch</flux:button>
                    @endunless
                @else
                    <flux:text>Only a broadcaster can reset it.</flux:text>
                @endcan
            @else
                <div class="flex flex-wrap items-end gap-2">
                    <flux:input wire:model="reason" label="Reason (optional)" class="max-w-sm" />
                    <flux:button variant="danger" icon="no-symbol" wire:click="kill" wire:confirm="Stop the bus for every game?">Kill switch</flux:button>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Running game</flux:heading>
            <flux:text>Chat drives the running game with <code>!do</code>. Switching games cancels any open vote.</flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" :variant="$global?->active_game === null ? 'primary' : 'outline'" wire:click="setGame('')">None</flux:button>
                @foreach ($games as $entry)
                    <flux:button size="sm" :variant="$global?->active_game === $entry['game']->key ? 'primary' : 'outline'"
                        wire:click="setGame('{{ $entry['game']->key }}')">{{ $entry['game']->label }}</flux:button>
                @endforeach
            </div>
        </section>

        @foreach ($games as $entry)
            @php($game = $entry['game'])
            @php($state = $entry['state'])
            <section class="space-y-3" wire:key="bus-game-{{ $game->key }}">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg">{{ $game->label }}</flux:heading>
                    @if ($state->paused)
                        <flux:badge color="amber">Paused</flux:badge>
                        <flux:button size="sm" wire:click="resume('{{ $game->key }}')">Resume</flux:button>
                    @else
                        <flux:button size="sm" variant="outline" wire:click="pause('{{ $game->key }}')">Pause</flux:button>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (\App\ControlBus\Mode::cases() as $mode)
                        <flux:button size="xs" :variant="$state->mode === $mode ? 'primary' : 'ghost'"
                            wire:click="setMode('{{ $game->key }}', '{{ $mode->value }}')"
                            wire:confirm="Switch to {{ $mode->label() }}? The open vote is cancelled.">{{ $mode->label() }}</flux:button>
                    @endforeach
                </div>
                <flux:text class="text-sm">Usage: {{ $game->usage() }}. Window: {{ $game->windowLengthSeconds() }} s (including platform lag).</flux:text>

                @if ($entry['window'])
                    <flux:text class="text-sm">Open vote #{{ $entry['window']->id }}, closes {{ $entry['window']->closes_at->diffForHumans() }}.</flux:text>
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($entry['options'] as $option)
                            <li class="py-2 flex items-center gap-3" wire:key="bus-option-{{ $entry['window']->id }}-{{ $option['number'] }}">
                                <span class="tabular-nums text-zinc-500 w-10">#{{ $option['number'] }}</span>
                                <span class="tabular-nums w-10">{{ $option['votes'] }}</span>
                                <span class="flex-1 {{ $option['vetoed'] ? 'line-through text-zinc-500' : '' }}">{{ $option['label'] }}</span>
                                @if ($option['subscriber'])
                                    <flux:badge size="sm" color="violet">sub</flux:badge>
                                @endif
                                @unless ($option['vetoed'])
                                    <flux:button size="xs" variant="danger"
                                        wire:click="vetoOption({{ $entry['window']->id }}, @js($option['key']))"
                                        wire:confirm="Veto this option?">Veto</flux:button>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach

        <section class="space-y-3">
            <flux:heading size="lg">Published actions</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($publications as $publication)
                    <li class="py-2 flex items-center gap-3" wire:key="bus-pub-{{ $publication->id }}">
                        <span class="text-zinc-500 w-40 tabular-nums">{{ $publication->created_at->toDateTimeString() }}</span>
                        <span class="w-24">{{ $publication->game }}</span>
                        <span class="flex-1 {{ $publication->vetoed_at ? 'line-through text-zinc-500' : '' }}">{{ trim($publication->verb.' '.$publication->argument) }}</span>
                        <span class="tabular-nums text-zinc-500">{{ $publication->votes }}/{{ $publication->total_votes }}</span>
                        @if ($publication->vetoed_at)
                            <flux:badge size="sm" color="red">vetoed</flux:badge>
                        @else
                            <flux:button size="xs" variant="danger" wire:click="vetoPublication({{ $publication->id }})"
                                wire:confirm="Veto this action? Adapters are told to undo it.">Veto</flux:button>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">Nothing published yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Audit log: every chat action</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($ballots as $ballot)
                    <li class="py-2 flex gap-3" wire:key="bus-ballot-{{ $ballot->id }}">
                        <span class="text-zinc-500 w-40 tabular-nums">{{ $ballot->created_at->toDateTimeString() }}</span>
                        <span class="w-32">{{ $ballot->user?->name ?? 'deleted user' }}</span>
                        <span class="w-20 text-zinc-500">{{ $ballot->provider->label() }}</span>
                        <span class="flex-1">{{ trim(($ballot->option_number ? '#'.$ballot->option_number.' ' : '').$ballot->verb.' '.$ballot->argument) }}</span>
                        <span class="w-28 font-mono">{{ $ballot->status->value }}</span>
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">No chat actions yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
    @endvolt
</x-layouts.app>
