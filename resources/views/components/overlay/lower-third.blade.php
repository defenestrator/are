{{--
    Rotating call-to-action lower-third. All items share one grid cell, so the
    box is sized by the largest item and never jumps when the copy changes.

    Horizontal: bottom left of the 1920x1080 canvas.
    Vertical: above the Shorts/Reels caption block (about the bottom 400px)
    and clear of the right-hand action buttons (about 160px), so the platform
    UI never covers the URL.
--}}
@if (count($items) === 0)
    <x-overlay.empty message="No calls to action are configured." />
@else
    @if ($rotates())
        <style>{!! $keyframes() !!}</style>
    @endif

    <div data-cta class="absolute bottom-16 left-16 grid w-fit max-w-[1120px] vertical:bottom-[420px] vertical:left-12 vertical:right-[180px] vertical:w-auto vertical:max-w-none">
        @foreach ($items as $index => $item)
            <section
                data-cta-item="{{ $item['key'] }}"
                @class([
                    'overlay-panel col-start-1 row-start-1 flex overflow-hidden rounded-2xl',
                    'overlay-enter' => ! $rotates(),
                ])
                style="--cta-accent: {{ $item['accent'] }};@if ($rotates()) animation: overlay-cta-rotate {{ $cycleSeconds() }}s ease-in-out {{ $index * $seconds }}s infinite both;@endif"
            >
                <span class="w-2 shrink-0 bg-[var(--cta-accent)] vertical:w-3"></span>

                <div class="relative min-w-0 flex-1 px-10 py-7 vertical:px-10 vertical:py-9">
                    <p class="text-lg font-semibold uppercase tracking-[0.22em] text-[var(--cta-accent)] vertical:text-2xl">
                        {{ $item['eyebrow'] }}
                    </p>

                    <p class="mt-1 text-balance text-[2.75rem] font-bold leading-tight text-white vertical:mt-2 vertical:text-[3.25rem]">
                        {{ $item['headline'] }}
                    </p>

                    @if ($item['body'] !== '')
                        <p class="mt-2 text-pretty text-2xl leading-snug text-white/75 vertical:mt-3 vertical:text-[1.875rem]">
                            {{ $item['body'] }}
                        </p>
                    @endif

                    <p class="mt-5 inline-flex max-w-full items-center gap-3 rounded-full bg-white px-5 py-2 text-2xl font-semibold text-zinc-950 vertical:mt-6 vertical:px-6 vertical:py-3 vertical:text-[2.25rem]">
                        <flux:icon.arrow-up-right variant="outline" class="size-[1em] shrink-0 [&_path]:stroke-[2.5]" />
                        <span class="truncate">{{ $item['display_url'] }}</span>
                    </p>

                    @if ($rotates())
                        {{-- Fills over the item's turn, so the rotation reads as deliberate. --}}
                        <span aria-hidden="true" class="absolute inset-x-0 bottom-0 h-1 origin-left bg-[var(--cta-accent)]/60"
                              style="animation: overlay-cta-progress {{ $cycleSeconds() }}s linear {{ $index * $seconds }}s infinite both;"></span>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
@endif
