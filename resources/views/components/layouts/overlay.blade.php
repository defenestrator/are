{{--
    Minimal layout for OBS browser sources: no app chrome, a transparent page,
    and a fixed canvas matching the OBS source size (1920x1080 or 1080x1920).
--}}
@props([
    'overlay',
    'layout',
    'scripts' => [],
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark"
      data-overlay="{{ $overlay->value }}" data-layout="{{ $layout->value }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width={{ $layout->width() }}, initial-scale=1" />
        {{-- The overlay URL carries its access token. --}}
        <meta name="referrer" content="no-referrer" />
        <meta name="robots" content="noindex, nofollow" />

        <title>{{ $overlay->title() }} overlay · ARE</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', ...$scripts])
    </head>
    <body class="m-0 overflow-hidden bg-transparent font-sans text-white antialiased">
        <main class="relative overflow-hidden" style="width: {{ $layout->width() }}px; height: {{ $layout->height() }}px;">
            {{ $slot }}
        </main>
    </body>
</html>
