<?php

use App\ControlBus\BusOverlay;
use App\ControlBus\Game;
use App\Enums\Overlay;
use App\Livewire\Overlays\PollingOverlay;

/*
 * The Chat Control Bus on stream (#138): the open vote's options and counts,
 * the time left, the mode, a winner waiting for a moderator, the latest
 * result, and PAUSED or KILLED. Everything shown comes from
 * BusOverlay::snapshot(), which carries no user data and no free text before
 * a moderator approves it. Kept live by resources/js/live-bus.js.
 */
new class extends PollingOverlay {
    protected function overlay(): Overlay
    {
        return Overlay::Bus;
    }

    public function with(): array
    {
        $current = $this->tokenIsCurrent();

        return [
            'current' => $current,
            'snapshot' => $current ? BusOverlay::forOverlay() : null,
            'games' => array_keys(Game::all()),
        ];
    }
}; ?>

@php
    $window = $snapshot['window'] ?? null;
    $awaiting = $snapshot['awaiting'] ?? null;
    $result = $snapshot['result'] ?? null;
    $killed = (bool) ($snapshot['killed'] ?? false);
    $paused = (bool) ($snapshot['paused'] ?? false);
    $total = max(1, (int) ($window['total'] ?? 0));
    $clock = fn (int $seconds) => sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
@endphp

<div class="absolute bottom-16 right-16 w-[640px] vertical:inset-x-14 vertical:bottom-[440px] vertical:right-14 vertical:w-auto">
    <div x-data="liveBus"
         data-live="{{ $current ? 'on' : 'off' }}"
         data-bus-games="{{ json_encode($games) }}"
         data-snapshot="{{ json_encode($snapshot) }}">
        @if ($snapshot === null)
            <x-overlay.empty message="No chat game is running." />
        @elseif ($killed)
            <section data-bus-state="killed" class="overlay-panel overlay-enter flex items-center gap-5 rounded-2xl ring-2 ring-red-500/70 px-8 py-6 vertical:px-10 vertical:py-8">
                <flux:icon.no-symbol variant="solid" class="size-12 shrink-0 text-red-400 vertical:size-16" />
                <div>
                    <p class="text-3xl font-black uppercase tracking-[0.18em] text-red-300 vertical:text-[2.75rem]">Killed</p>
                    <p class="mt-1 text-xl text-white/75 vertical:text-[1.75rem]">Chat control is off. Nothing reaches the game.</p>
                </div>
            </section>
        @else
            <section data-bus-state="{{ $paused ? 'paused' : 'running' }}" class="overlay-panel overlay-enter overflow-hidden rounded-2xl">
                <header class="flex items-center justify-between gap-4 px-8 pt-6 vertical:px-10 vertical:pt-8">
                    <div class="min-w-0">
                        <p class="text-lg font-semibold uppercase tracking-[0.2em] text-[#7c5cff] vertical:text-2xl">Chat game</p>
                        <p class="truncate text-[2rem] font-bold leading-tight text-white vertical:text-[2.5rem]">{{ $snapshot['game']['label'] }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span data-bus-mode class="rounded-full bg-white/10 px-4 py-1 text-lg font-semibold uppercase tracking-wider text-white/85 vertical:text-2xl">{{ $snapshot['mode_label'] }}</span>
                        @if ($window && ! $paused)
                            <span data-countdown data-seconds-left="{{ $window['seconds_left'] }}" class="font-mono text-4xl font-bold tabular-nums text-white vertical:text-5xl">{{ $clock($window['seconds_left']) }}</span>
                        @endif
                    </div>
                </header>

                @if ($paused)
                    <p data-bus-paused class="mx-8 mt-5 rounded-xl bg-amber-400/15 px-5 py-3 text-center text-3xl font-black uppercase tracking-[0.25em] text-amber-300 vertical:mx-10 vertical:text-[2.5rem]">Paused</p>
                @endif

                <div class="px-8 pb-6 pt-5 vertical:px-10 vertical:pb-8">
                    @if ($window)
                        <ol class="flex flex-col gap-3">
                            @foreach ($window['options'] as $option)
                                <li data-option="{{ $option['number'] }}" @class(['relative overflow-hidden rounded-xl bg-white/5', 'opacity-40' => $option['vetoed']])>
                                    <span data-option-bar aria-hidden="true" class="absolute inset-y-0 left-0 bg-[#7c5cff]/35 transition-[width] duration-500" style="width: {{ $option['vetoed'] ? 0 : round(100 * $option['votes'] / $total) }}%"></span>
                                    <div class="relative flex items-center gap-4 px-5 py-3">
                                        <span class="font-mono text-2xl font-bold text-white/60 vertical:text-3xl">#{{ $option['number'] }}</span>
                                        <span class="min-w-0 flex-1 truncate text-2xl text-white vertical:text-[2rem]">
                                            <span class="font-semibold">{{ $option['verb'] }}</span>
                                            @if ($option['label'] !== null)
                                                {{ $option['label'] }}
                                            @elseif (BusOverlay::isFreeText(Game::find($snapshot['game']['key']), $option['verb']))
                                                <span class="italic text-white/55">hidden until approved</span>
                                            @endif
                                        </span>
                                        <span data-option-votes class="font-mono text-2xl font-bold tabular-nums text-white vertical:text-3xl">{{ $option['vetoed'] ? 'vetoed' : $option['votes'] }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                        @if ($window['options'] === [])
                            <p class="text-2xl text-white/70 vertical:text-[2rem]">The vote is open: suggest an action.</p>
                        @endif
                        <p class="mt-4 text-xl text-white/60 vertical:text-[1.625rem]">Vote in chat: <span class="font-mono text-white/85">!do #number</span></p>
                    @elseif ($awaiting)
                        <p data-bus-awaiting class="text-2xl text-white vertical:text-[2rem]">
                            <span class="font-semibold">{{ $awaiting['verb'] }}</span> won with {{ $awaiting['votes'] }} of {{ $awaiting['total'] }} votes.
                        </p>
                        <p class="mt-2 text-2xl font-semibold text-amber-300 vertical:text-[2rem]">Awaiting moderator approval</p>
                    @elseif ($result)
                        <div data-bus-result="{{ $result['status'] }}" data-seconds-left="{{ $result['seconds_left'] }}">
                            @if ($result['status'] === 'published')
                                <p class="text-xl uppercase tracking-[0.2em] text-white/60 vertical:text-2xl">Chat chose</p>
                                <p class="mt-1 text-[2rem] font-bold leading-tight text-white vertical:text-[2.5rem]">
                                    {{ $result['verb'] }}@if ($result['label'] !== null) <span class="font-normal">{{ $result['label'] }}</span>@endif
                                </p>
                            @else
                                <p class="text-2xl text-white/80 vertical:text-[2rem]">The moderators turned down chat's <span class="font-semibold">{{ $result['verb'] }}</span>.</p>
                            @endif
                        </div>
                    @elseif ($snapshot['mode'] === 'anarchy')
                        <p class="text-2xl text-white/80 vertical:text-[2rem]">Anarchy: every <span class="font-mono">!do</span> goes straight through.</p>
                    @else
                        <p class="text-2xl text-white/80 vertical:text-[2rem]">Waiting for chat: <span class="font-mono">!do</span> starts the next vote.</p>
                    @endif
                </div>
            </section>
        @endif
    </div>
</div>
