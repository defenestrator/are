{{--
    An overlay with nothing to show stays invisible on stream. The text is for
    screen readers and for whoever is checking the source in a browser.
--}}
@props(['message'])

<p data-overlay-empty class="sr-only">{{ $message }}</p>
