<x-layouts.app>
    <div class="space-y-6">
        <header class="space-y-2">
            <flux:heading size="xl" level="1">Launch readiness</flux:heading>
            <flux:text>
                What is set up and what is still missing before the show. Each line says what was found and, if something is
                wrong, the one thing to do about it. Values are never shown, only whether they are set.
            </flux:text>
        </header>

        {{-- Lazy: the checks that call Twitch, Redis or the Reverb socket never hold up the page. --}}
        <livewire:admin.readiness lazy />
    </div>
</x-layouts.app>
