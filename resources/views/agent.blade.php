<?php

use App\Agent\AgentControls;
use App\Agent\AgentGate;
use App\ControlBus\ControlBus;
use App\Models\Agent;
use App\Models\AgentClaim;
use App\Models\AgentRequest;
use App\Models\BusControl;
use Livewire\Volt\Component;

new class extends Component {
    public string $reason = '';

    public ?int $openRequest = null;

    // Every action is a public endpoint: each authorises itself here, and
    // the classes it calls authorise again.
    public function kill(ControlBus $bus): void
    {
        $this->authorize('moderate');
        $bus->kill(auth()->user(), $this->reason !== '' ? $this->reason : 'agent page');
        $this->reset('reason');
    }

    public function stopAgent(AgentControls $controls): void
    {
        $this->authorize('moderate');
        $controls->stop(auth()->user());
    }

    public function startAgent(AgentControls $controls): void
    {
        $this->authorize('moderate');
        $controls->start(auth()->user());
    }

    public function showRequest(int $id): void
    {
        $this->authorize('moderate');
        $this->openRequest = $this->openRequest === $id ? null : $id;
    }

    public function with(): array
    {
        return [
            'refusal' => AgentGate::refusal(),
            'global' => BusControl::find(BusControl::GLOBAL),
            'agents' => Agent::with('tokens')->orderBy('name')->get(),
            'claims' => AgentClaim::with('agent')->latest('id')->limit(50)->get(),
            'requests' => AgentRequest::with('agent')->latest('id')->limit(50)->get(),
            'scene' => config('agent.obs.intermission_scene'),
            'obsDriver' => config('agent.obs.driver'),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('agent')
    {{-- A moderator tool: polling keeps the agent's activity current. --}}
    <div class="space-y-10" wire:poll.5s>
        <flux:heading size="xl" level="1">VTuber agent</flux:heading>

        <section class="space-y-3">
            @if ($refusal === 'killed')
                <flux:callout variant="danger" icon="no-symbol" heading="Kill switch is on: the agent and the chat game are stopped.">
                    <flux:callout.text>A broadcaster resets it on the Chat game page.</flux:callout.text>
                </flux:callout>
            @else
                <div class="flex flex-wrap items-end gap-2">
                    <flux:input wire:model="reason" label="Reason (optional)" class="max-w-sm" />
                    <flux:button variant="danger" icon="no-symbol" wire:click="kill"
                        wire:confirm="Stop the agent and the chat game, and cut to {{ $scene }}?">Kill switch</flux:button>
                </div>
                <flux:text class="text-sm">Stops the agent and the chat game at once, and cuts the stream to the {{ $scene }} scene (OBS driver: {{ $obsDriver }}).</flux:text>
            @endif

            <div class="flex items-center gap-3">
                @if ($refusal === 'paused')
                    <flux:badge color="amber">Agent stopped</flux:badge>
                    <flux:button size="sm" wire:click="startAgent">Let the agent act again</flux:button>
                @elseif ($refusal === 'disabled')
                    <flux:badge color="zinc">AGENT_ENABLED is false in this deployment</flux:badge>
                @elseif ($refusal === null)
                    <flux:badge color="green">Agent may act</flux:badge>
                    <flux:button size="sm" variant="outline" wire:click="stopAgent">Stop only the agent</flux:button>
                @endif
            </div>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Agents</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($agents as $agent)
                    <li class="py-2 flex gap-3" wire:key="agent-{{ $agent->id }}">
                        <span class="w-48 font-mono">{{ $agent->name }}</span>
                        <span class="text-zinc-500">
                            @php($used = $agent->tokens->max('last_used_at'))
                            {{ $agent->tokens->count() }} token(s), last used {{ $used ? \Illuminate\Support\Carbon::parse($used)->diffForHumans() : 'never' }}
                        </span>
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">No agents yet. Issue a token with <code>php artisan agent:token &lt;name&gt;</code>.</li>
                @endforelse
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">What the agent took and said</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($claims as $claim)
                    <li class="py-3 space-y-1" wire:key="claim-{{ $claim->id }}">
                        <div class="flex gap-3">
                            <span class="text-zinc-500 w-40 tabular-nums">{{ $claim->claimed_at->toDateTimeString() }}</span>
                            <span class="w-32 font-mono">{{ $claim->agent?->name }}</span>
                            <span class="flex-1 font-medium">{{ $claim->question_text }}</span>
                        </div>
                        <div class="pl-[18rem] text-zinc-600 dark:text-zinc-300"><span class="text-zinc-500">Why:</span> {{ $claim->reason }}</div>
                        @if ($claim->answered_at)
                            <div class="pl-[18rem]">
                                <span class="text-zinc-500">Said:</span> {{ $claim->answer }}
                                <flux:badge size="sm" :color="match ($claim->moderation_verdict) { 'allowed' => 'green', 'flagged' => 'amber', default => 'red' }">{{ $claim->moderation_verdict }}</flux:badge>
                                @if (! empty($claim->moderation['categories']))
                                    <span class="text-zinc-500">({{ implode(', ', $claim->moderation['categories']) }})</span>
                                @endif
                            </div>
                        @else
                            <div class="pl-[18rem] text-zinc-500">Not answered yet.</div>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">The agent has not claimed anything yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Request log</flux:heading>
            <flux:text class="text-sm">Every request an agent token made, and what ARE answered. Click one to see the bodies.</flux:text>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                @forelse ($requests as $logged)
                    <li class="py-2" wire:key="agent-request-{{ $logged->id }}">
                        <button type="button" class="flex w-full gap-3 text-left cursor-pointer" wire:click="showRequest({{ $logged->id }})">
                            <span class="text-zinc-500 w-40 tabular-nums">{{ $logged->created_at->toDateTimeString() }}</span>
                            <span class="w-32 font-mono">{{ $logged->agent?->name ?? '-' }}</span>
                            <span class="w-14 font-mono">{{ $logged->method }}</span>
                            <span class="flex-1 font-mono">{{ $logged->path }}</span>
                            <span class="w-12 tabular-nums">{{ $logged->status }}</span>
                            <span class="w-28">
                                @if ($logged->refused)
                                    <flux:badge size="sm" color="red">{{ $logged->refused }}</flux:badge>
                                @endif
                            </span>
                            <span class="w-16 tabular-nums text-zinc-500">{{ $logged->duration_ms }} ms</span>
                        </button>
                        @if ($openRequest === $logged->id)
                            <div class="mt-2 grid gap-2 md:grid-cols-2">
                                <pre class="whitespace-pre-wrap break-all rounded bg-zinc-100 dark:bg-zinc-800 p-2 text-xs">{{ $logged->request ?? '(no body)' }}</pre>
                                <pre class="whitespace-pre-wrap break-all rounded bg-zinc-100 dark:bg-zinc-800 p-2 text-xs">{{ $logged->response ?? '(no body)' }}</pre>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">No agent requests yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
    @endvolt
</x-layouts.app>
