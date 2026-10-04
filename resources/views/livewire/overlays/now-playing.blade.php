<?php

use App\Enums\Overlay;
use App\Livewire\Overlays\PollingOverlay;
use App\SongRequests;

/*
 * The song request on air (#13): title, artist and its attribution line,
 * which links back to the stream-safe pack. Blank when nothing is playing.
 */
new class extends PollingOverlay {
    protected function overlay(): Overlay
    {
        return Overlay::NowPlaying;
    }

    public function with(): array
    {
        $current = $this->tokenIsCurrent();

        return [
            'current' => $current,
            'request' => $current ? SongRequests::nowPlaying() : null,
        ];
    }
}; ?>

<div @if ($current) wire:poll.5s.keep-alive @endif
     class="absolute left-16 top-16 w-[720px] vertical:inset-x-14 vertical:top-[200px] vertical:w-auto">
    @if ($request)
        <section data-now-playing class="overlay-panel overlay-enter flex overflow-hidden rounded-2xl" wire:key="np-{{ $request->id }}">
            <span class="w-2 shrink-0 bg-[#7c5cff] vertical:w-3"></span>
            <div class="min-w-0 flex-1 px-8 py-6 vertical:px-10 vertical:py-8">
                <p class="flex items-center gap-3 text-lg font-semibold uppercase tracking-[0.2em] text-[#7c5cff] vertical:text-2xl">
                    <flux:icon.musical-note variant="solid" class="size-[1em]" />
                    Now playing
                </p>
                <p class="mt-1 truncate text-[2.5rem] font-bold leading-tight text-white vertical:text-[3rem]">{{ $request->track->title }}</p>
                <p class="truncate text-2xl text-white/80 vertical:text-[1.875rem]">{{ $request->track->artist }}</p>
                <p class="mt-3 text-lg text-white/60 vertical:text-2xl">{{ $request->track->creditLine() }}</p>
                <p class="mt-1 text-lg text-white/60 vertical:text-2xl">Requested by {{ $request->requester_name }}</p>
            </div>
        </section>
    @else
        <x-overlay.empty message="Nothing is playing." />
    @endif
</div>
