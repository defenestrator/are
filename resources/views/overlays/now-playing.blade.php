{{--
    Now playing. There is no track source yet (#13), so this renders its
    empty state; the anchored slot is where the card will go.
--}}
<x-layouts.overlay :overlay="$overlay" :layout="$layout">
    <div class="absolute left-16 top-16 vertical:inset-x-14 vertical:top-[200px]">
        <x-overlay.empty message="Nothing is playing." />
    </div>
</x-layouts.overlay>
