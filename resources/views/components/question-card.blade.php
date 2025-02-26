<div class="p-3 sm:p-4 rounded-lg">
    <div class="flex flex-row sm:items-center gap-2">
        <flux:avatar src="{{ $question->user->twitch_avatar_url }}" size="xs" class="shrink-0" />

        <div class="flex flex-col gap-0.5 sm:gap-2 sm:flex-row sm:items-center">
            <div class="flex items-center gap-2">
                <flux:heading>{{ $question->user->name }}</flux:heading>
                </div>
            </div>
        </div>

        <div class="min-h-2 sm:min-h-1"></div>

        <div class="pl-8">
        <flux:text variant="strong">{{ $question->question }}</flux:text>

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

