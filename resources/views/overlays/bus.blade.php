<x-layouts.overlay :overlay="$overlay" :layout="$layout" :scripts="['resources/js/overlay.js']">
    <livewire:overlays.bus :layout="$layout->value" />
</x-layouts.overlay>
