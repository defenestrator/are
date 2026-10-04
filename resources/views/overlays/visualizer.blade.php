{{--
    The Three.js visualizer from /visualizer on a transparent canvas.
    visualizer.js sizes itself to #visualizer-container and clears to
    transparent when the page is an overlay.
--}}
<x-layouts.overlay :overlay="$overlay" :layout="$layout" :scripts="['resources/js/visualizer.js']">
    <x-visualizer />
    <div id="visualizer-container" class="absolute inset-0"></div>
</x-layouts.overlay>
