{{--
    Captions. There is no caption source yet, so this renders its empty
    state; the anchored slot is where caption lines will go, centred above
    the lower-third (horizontal) or above the Shorts UI (vertical).
--}}
<x-layouts.overlay :overlay="$overlay" :layout="$layout">
    <div class="absolute inset-x-[240px] bottom-[260px] flex justify-center vertical:inset-x-14 vertical:bottom-[760px]">
        <x-overlay.empty message="No captions yet." />
    </div>
</x-layouts.overlay>
