<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => 'Stream-safe music pack · ARE'])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        <main class="mx-auto max-w-3xl space-y-8 p-6 md:p-10">
            <header class="space-y-2">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2">
                    <x-app-logo-icon class="size-8 fill-current text-black dark:text-white" />
                    <span class="sr-only">ARE</span>
                </a>
                <flux:heading size="xl" level="1">Stream-safe music pack</flux:heading>
                <flux:text>
                    Original music from the EDOS show that you may use on your own streams and videos.
                    Credit each track with its attribution line, which links back to <flux:link href="{{ route('music.index') }}">ARE</flux:link>.
                </flux:text>
            </header>

            <ul class="space-y-4">
                @forelse ($tracks as $track)
                    <li class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <flux:heading size="lg" level="2">{{ $track->title }}</flux:heading>
                            <flux:text>{{ $track->artist }}</flux:text>
                            @if ($track->formattedDuration())
                                <flux:text class="tabular-nums">{{ $track->formattedDuration() }}</flux:text>
                            @endif
                        </div>
                        <div>
                            <flux:text size="sm">Attribution</flux:text>
                            <p class="select-all rounded bg-zinc-100 px-2 py-1 font-mono text-sm dark:bg-zinc-900 dark:text-zinc-200">{{ $track->creditLine() }}</p>
                        </div>
                        <div class="flex gap-2">
                            <flux:button size="sm" icon="arrow-down-tray" href="{{ route('music.download', $track) }}">Download</flux:button>
                            @if ($track->stems_path)
                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" href="{{ route('music.stems', $track) }}">Stems</flux:button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li><flux:text>No tracks in the pack yet.</flux:text></li>
                @endforelse
            </ul>
        </main>
        @fluxScripts
    </body>
</html>
