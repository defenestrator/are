<div>
    {{-- Header --}}
    <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 flex items-center">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:brand href="#" name="Podium" class="max-lg:hidden">
            <div class="flex aspect-square items-center justify-center rounded-md bg-accent text-accent-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-mic-vocal">
                    <path d="m11 7.601-5.994 8.19a1 1 0 0 0 .1 1.298l.817.818a1 1 0 0 0 1.314.087L15.09 12"/>
                    <path d="M16.5 21.174C15.5 20.5 14.372 20 13 20c-2.058 0-3.928 2.356-6 2-2.072-.356-2.775-3.369-1.5-4.5"/>
                    <circle cx="16" cy="7" r="5"/>
                </svg>
            </div>
        </flux:brand>

        <flux:navbar class="-mb-px max-lg:hidden">
            <flux:navbar.item href="#">Questions</flux:navbar.item>
            <flux:navbar.item href="#">Leaderboard</flux:navbar.item>
            <flux:navbar.item href="#">Announcements</flux:navbar.item>
        </flux:navbar>

        <flux:spacer />

        <div class="rounded-full bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-500 p-1 flex items-center p-1 pr-2.5 gap-2">
            <div class="relative rounded-full size-6">
                <img src="https://fluxui.dev/img/demo/prime.png" class="size-full rounded-full" />
                <div class="absolute -bottom-px -right-px rounded-full size-2 border-2 border-red-50 dark:border-red-950 bg-red-600 dark:bg-red-500"></div>
            </div>

            <div class="text-sm font-medium text-red-600 dark:text-white">Live</div>
        </div>

        <flux:separator vertical variant="subtle" class="my-4 mx-3"/>

        <flux:dropdown position="top" align="end">
            <flux:profile class="cursor-pointer" avatar="https://fluxui.dev/img/demo/teej.png" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                            <flux:avatar src="https://fluxui.dev/img/demo/teej.png" size="sm" class="shrink-0" />

                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold">John Doe</span>
                                <span class="truncate text-xs">john@doe.com</span>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item href="/settings/profile" icon="cog">Settings</flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                    Log out
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{-- Mobile sidebar --}}
    <flux:sidebar stashable sticky class="lg:hidden border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="#" name="Podium" class="px-2">
            <div class="flex aspect-square items-center justify-center rounded-md bg-accent text-accent-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-mic-vocal">
                    <path d="m11 7.601-5.994 8.19a1 1 0 0 0 .1 1.298l.817.818a1 1 0 0 0 1.314.087L15.09 12"/>
                    <path d="M16.5 21.174C15.5 20.5 14.372 20 13 20c-2.058 0-3.928 2.356-6 2-2.072-.356-2.775-3.369-1.5-4.5"/>
                    <circle cx="16" cy="7" r="5"/>
                </svg>
            </div>
        </flux:brand>

        <flux:navlist variant="outline">
            <flux:navlist.group>
                <flux:navlist.item href="#">Questions</flux:navlist.item>
                <flux:navlist.item href="#">Leaderboard</flux:navlist.item>
                <flux:navlist.item href="#">Announcements</flux:navlist.item>
            </flux:navlist.group>
        </flux:navlist>

        <flux:spacer />
    </flux:sidebar>

    {{-- Main content --}}
    <div>
        {{-- Secondary header --}}
        <div class="sm:border-b border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
            <div class="max-w-7xl px-6 sm:px-8 py-3 mx-auto flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-2">
                <div class="max-sm:hidden flex items-baseline gap-3">
                    <flux:heading size="lg" class="text-lg">Questions</flux:heading>

                    <flux:text>77</flux:text>
                </div>

                <flux:spacer />

                <div class="flex items-center gap-2">
                    <flux:select variant="listbox" class="sm:max-w-fit">
                        <x-slot name="trigger">
                            <flux:select.button size="sm">
                                <flux:icon.funnel variant="micro" class="mr-2 text-zinc-400" />
                                <flux:select.selected />
                            </flux:select.button>
                        </x-slot>

                        <flux:select.option value="all" selected>All</flux:select.option>
                        <flux:select.option value="unapproved">Unapproved</flux:select.option>
                        <flux:select.option value="approved">Approved</flux:select.option>
                    </flux:select>

                    <flux:select variant="listbox" class="sm:max-w-fit">
                        <x-slot name="trigger">
                            <flux:select.button size="sm">
                                <flux:icon.arrows-up-down variant="micro" class="mr-2 text-zinc-400" />
                                <flux:select.selected />
                            </flux:select.button>
                        </x-slot>

                        <flux:select.option value="popular" selected>Most popular</flux:select.option>
                        <flux:select.option value="newest">Newest</flux:select.option>
                        <flux:select.option value="oldest">Oldest</flux:select.option>
                    </flux:select>
                </div>

                <flux:button icon="pencil-square" size="sm" variant="primary">New question</flux:button>
            </div>
        </div>

        <div class="min-h-4 sm:min-h-10"></div>

        <div class="mx-auto max-w-lg max-sm:px-2">
            {{-- Loop: questions... --}}

            <div class="p-3 sm:p-4 rounded-lg">
                <div class="flex flex-row sm:items-center gap-2">
                    <flux:avatar src="https://randomuser.me/api/portraits/men/1.jpg" size="xs" class="shrink-0" />

                    <div class="flex flex-col gap-0.5 sm:gap-2 sm:flex-row sm:items-center">
                        <div class="flex items-center gap-2">
                            <flux:heading>John Doe</flux:heading>

                            <flux:badge color="lime" size="sm" icon="check-badge" inset="top bottom">Moderator</flux:badge>
                        </div>

                        <flux:text class="text-sm">2 days ago</flux:text>
                    </div>
                </div>

                <div class="min-h-2 sm:min-h-1"></div>

                <div class="pl-8">
                    <flux:text variant="strong">What is the best way to learn Laravel?</flux:text>

                    <div class="min-h-2"></div>

                    <div class="flex items-center gap-0">
                        <flux:button wire:click="$js.optimisticVote($el)" variant="ghost" size="sm" inset="left" class="flex items-center gap-2" :loading="false">
                            <flux:icon.hand-thumb-up name="hand-thumb-up" variant="outline" class="size-4 text-zinc-400 [&_path]:stroke-[2.25]" />

                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">12</flux:text>
                        </flux:button>

                        <flux:dropdown>
                            <flux:button icon="ellipsis-horizontal" variant="subtle" size="sm" />

                            <flux:menu class="min-w-0">
                                <flux:menu.item icon="pencil-square">Edit</flux:menu.item>
                                <flux:menu.item variant="danger" icon="trash">Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            </div>

            <div class="p-3 sm:p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/50">
                <div class="flex flex-row sm:items-center gap-2">
                    <flux:avatar src="https://randomuser.me/api/portraits/men/2.jpg" size="xs" class="shrink-0" />

                    <div class="flex flex-col gap-0.5 sm:gap-2 sm:flex-row sm:items-center">
                        <div class="flex items-center gap-2">
                            <flux:heading>Sarah Smith</flux:heading>
                        </div>

                        <flux:text class="text-sm">3 days ago</flux:text>
                    </div>
                </div>

                <div class="min-h-2 sm:min-h-1"></div>

                <div class="pl-8">
                    <flux:text variant="strong">I'm trying to learn Laravel, but I'm not sure where to start. Any advice?</flux:text>

                    <div class="min-h-2"></div>

                    <div class="flex items-center gap-2">
                        <flux:button size="sm">Approve</flux:button>
                        <flux:button size="sm" variant="filled" class="text-red-600! dark:text-red-500!">Delete</flux:button>
                    </div>
                </div>
            </div>

            <div class="p-3 sm:p-4 rounded-lg">
                <div class="flex flex-row sm:items-center gap-2">
                    <flux:avatar src="https://randomuser.me/api/portraits/men/3.jpg" size="xs" class="shrink-0" />

                    <div class="flex flex-col gap-0.5 sm:gap-2 sm:flex-row sm:items-center">
                        <div class="flex items-center gap-2">
                            <flux:heading>Jane Doe</flux:heading>
                        </div>

                        <flux:text class="text-sm">4 days ago</flux:text>
                    </div>
                </div>

                <div class="min-h-2 sm:min-h-1"></div>

                <div class="pl-8">
                    <flux:text variant="strong">Where can I find the best tutorials for Laravel?</flux:text>

                    <div class="min-h-2"></div>

                    <div class="flex items-center gap-0">
                        <flux:button wire:click="$js.optimisticVote($el)" variant="ghost" size="sm" inset="left" class="flex items-center gap-2" :loading="false">
                            <flux:icon.hand-thumb-up name="hand-thumb-up" variant="solid" class="size-4 text-accent-content" data-animate-wiggle />

                            <flux:text class="text-sm text-accent-content tabular-nums">92</flux:text>
                        </flux:button>

                        <flux:dropdown>
                            <flux:button icon="ellipsis-horizontal" variant="subtle" size="sm" />

                            <flux:menu class="min-w-0">
                                <flux:menu.item icon="pencil-square">Edit</flux:menu.item>
                                <flux:menu.item variant="danger" icon="trash">Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            </div>

            <div class="p-3 sm:p-4 rounded-lg">
                <div class="flex flex-row sm:items-center gap-2">
                    <flux:avatar src="https://randomuser.me/api/portraits/men/4.jpg" size="xs" class="shrink-0" />

                    <div class="flex flex-col gap-0.5 sm:gap-2 sm:flex-row sm:items-center">
                        <div class="flex items-center gap-2">
                            <flux:heading>Samantha Doe</flux:heading>
                        </div>

                        <flux:text class="text-sm">5 days ago</flux:text>
                    </div>
                </div>

                <div class="min-h-2 sm:min-h-1"></div>

                <div class="pl-8">
                    <flux:text variant="strong">When is the best time to use Tailwind CSS?</flux:text>

                    <div class="min-h-2"></div>

                    <div class="flex items-center gap-0">
                        <flux:button wire:click="$js.optimisticVote($el)" variant="ghost" size="sm" inset="left" class="flex items-center gap-2" :loading="false">
                            <flux:icon.hand-thumb-up name="hand-thumb-up" variant="solid" class="size-4 text-accent-content" data-animate-wiggle />

                            <flux:text class="text-sm text-accent-content tabular-nums">100</flux:text>
                        </flux:button>

                        <flux:dropdown>
                            <flux:button icon="ellipsis-horizontal" variant="subtle" size="sm" />

                            <flux:menu class="min-w-0">
                                <flux:menu.item icon="pencil-square">Edit</flux:menu.item>
                                <flux:menu.item variant="danger" icon="trash">Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
