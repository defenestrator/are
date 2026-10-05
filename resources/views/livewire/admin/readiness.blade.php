<?php

use App\Readiness\ReadinessChecks;
use App\Readiness\Status;
use Livewire\Volt\Component;

new class extends Component {
    public function mount(): void
    {
        $this->authorize('viewReadiness');
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="text-sm text-zinc-500" role="status">Running the checks…</div>
            HTML;
    }

    public function refresh(): void
    {
        // Every action is a public endpoint: re-check the gate.
        $this->authorize('viewReadiness');
    }

    public function with(ReadinessChecks $checks): array
    {
        $groups = $checks->all();

        return [
            'groups' => $groups,
            'overall' => ReadinessChecks::worst($groups),
            'counts' => collect($groups)->flatten()->countBy(fn ($check) => $check->status->value),
            'checkedAt' => now(),
        ];
    }
}; ?>

<div class="space-y-8">
    <div class="flex flex-wrap items-center gap-3">
        <flux:badge size="lg" :color="$overall->color()" data-overall="{{ $overall->value }}">
            @switch($overall)
                @case(Status::Fail) Not ready: {{ $counts->get('fail', 0) }} missing @break
                @case(Status::Warn) Nearly ready: {{ $counts->get('warn', 0) }} to check @break
                @default Ready for the show
            @endswitch
        </flux:badge>
        <flux:text class="text-sm">
            {{ $counts->get('ok', 0) }} ready, {{ $counts->get('warn', 0) }} to check, {{ $counts->get('fail', 0) }} missing.
            Checked {{ $checkedAt->format('H:i:s') }} {{ config('app.timezone') }}.
        </flux:text>
        <flux:spacer />
        <flux:button size="sm" icon="arrow-path" wire:click="refresh">Check again</flux:button>
    </div>

    @foreach ($groups as $title => $checks)
        <section class="space-y-2" wire:key="group-{{ Str::slug($title) }}">
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($checks as $check)
                    <li class="py-3 flex gap-3" wire:key="check-{{ Str::slug($title.'-'.$check->name) }}" data-check="{{ $check->name }}" data-status="{{ $check->status->value }}">
                        <div class="w-24 shrink-0">
                            <flux:badge size="sm" :color="$check->status->color()">{{ $check->status->label() }}</flux:badge>
                        </div>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="font-medium">{{ $check->name }}</div>
                            <flux:text class="text-sm">{{ $check->summary }}</flux:text>
                            @if ($check->details)
                                <ul class="text-xs font-mono text-zinc-500 list-disc pl-5">
                                    @foreach ($check->details as $detail)
                                        <li>{{ $detail }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($check->fix)
                                <p class="text-sm"><span class="font-semibold">Fix:</span> <span class="font-mono">{{ $check->fix }}</span></p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
