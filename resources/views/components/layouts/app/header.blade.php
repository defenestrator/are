<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <a href="/vote" class="ml-2 mr-5 flex items-center space-x-2 lg:ml-0" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="hand-thumb-up" href="/vote" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Ideas') }}
                </flux:navbar.item>
                @can('moderate')
                    <flux:navbar.item icon="shield-check" href="{{ route('moderation') }}" :current="request()->routeIs('moderation')" wire:navigate>
                        {{ __('Moderation') }}
                    </flux:navbar.item>
                    {{-- TrackPolicy::viewAny is the moderate gate; nesting here avoids re-running its queries. --}}
                    <flux:navbar.item icon="musical-note" href="{{ route('music.catalogue') }}" :current="request()->routeIs('music.catalogue')" wire:navigate>
                        {{ __('Music') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="queue-list" href="{{ route('music.requests') }}" :current="request()->routeIs('music.requests')" wire:navigate>
                        {{ __('Requests') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="film" href="{{ route('clips.index') }}" :current="request()->routeIs('clips.index')" wire:navigate>
                        {{ __('Clips') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="puzzle-piece" href="{{ route('bus') }}" :current="request()->routeIs('bus')" wire:navigate>
                        {{ __('Chat game') }}
                    </flux:navbar.item>
                @endcan
                {{-- Leads and attribution share one broadcaster-only rule: LeadPolicy::viewAny. --}}
                @can('viewAny', App\Models\Lead::class)
                    <flux:navbar.item icon="inbox" href="{{ route('leads.index') }}" :current="request()->routeIs('leads.index')" wire:navigate>
                        {{ __('Leads') }}
                    </flux:navbar.item>
                    <flux:navbar.item icon="chart-bar" href="{{ route('admin.attribution') }}" :current="request()->routeIs('admin.attribution')">
                        {{ __('Attribution') }}
                    </flux:navbar.item>
                @endcan
                @can('viewReadiness')
                    <flux:navbar.item icon="check-badge" href="{{ route('admin.readiness') }}" :current="request()->routeIs('admin.readiness')">
                        {{ __('Readiness') }}
                    </flux:navbar.item>
                @endcan
            </flux:navbar>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="top" align="end">
                <flux:profile
                    class="cursor-pointer"
                    :avatar="auth()->user()->avatar_url"
                    :initials="auth()->user()->initials()"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        @if (auth()->user()->avatar_url)
                                            <img src="{{ auth()->user()->avatar_url }}" alt="" />
                                        @else
                                            {{ auth()->user()->initials() }}
                                        @endif
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="{{ route('settings') }}" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar stashable sticky class="hidden border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="hidden" icon="x-mark" />

            <a href="/vote" class="ml-1 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group heading="ARE">
                    <flux:navlist.item icon="hand-thumb-up" href="/vote" wire:navigate>
                    {{ __('Votes') }}
                    </flux:navlist.item>
                    @can('moderate')
                        <flux:navlist.item icon="shield-check" href="{{ route('moderation') }}" wire:navigate>
                            {{ __('Moderation') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="musical-note" href="{{ route('music.catalogue') }}" wire:navigate>
                            {{ __('Music') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="queue-list" href="{{ route('music.requests') }}" wire:navigate>
                            {{ __('Requests') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="film" href="{{ route('clips.index') }}" wire:navigate>
                            {{ __('Clips') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="puzzle-piece" href="{{ route('bus') }}" wire:navigate>
                            {{ __('Chat game') }}
                        </flux:navlist.item>
                    @endcan
                    @can('viewAny', App\Models\Lead::class)
                        <flux:navlist.item icon="inbox" href="{{ route('leads.index') }}" wire:navigate>
                            {{ __('Leads') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="chart-bar" href="{{ route('admin.attribution') }}">
                            {{ __('Attribution') }}
                        </flux:navlist.item>
                    @endcan
                    @can('viewReadiness')
                        <flux:navlist.item icon="check-badge" href="{{ route('admin.readiness') }}">
                            {{ __('Readiness') }}
                        </flux:navlist.item>
                    @endcan
                </flux:navlist.group>
            </flux:navlist>
        </flux:sidebar>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
