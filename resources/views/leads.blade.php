<?php

use App\Models\Lead;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public const PER_PAGE = 25;

    // Every Livewire request (paging included) is a public endpoint, so the
    // policy is checked on each one, not only by the route middleware.
    public function boot(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    public function with(): array
    {
        return [
            'leads' => Lead::with('shortLink')->latest('id')->paginate(self::PER_PAGE),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('leads')
    <div class="space-y-6">
        <div>
            <flux:heading size="xl" level="1">Leads</flux:heading>
            <flux:text>Professional Services enquiries from /about, newest first. Broadcasters only: this is personal data, so please do not copy it elsewhere.</flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Received</th>
                        <th class="py-2 pr-4 font-medium">From</th>
                        <th class="py-2 pr-4 font-medium">Message</th>
                        <th class="py-2 pr-4 font-medium">Source</th>
                        <th class="py-2 font-medium">Campaign (stream)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($leads as $lead)
                        <tr wire:key="lead-{{ $lead->id }}" class="align-top">
                            <td class="py-3 pr-4 whitespace-nowrap tabular-nums text-zinc-500" title="Consented {{ $lead->consented_at->toIso8601String() }}">
                                {{ $lead->created_at?->format('Y-m-d H:i') }}
                            </td>
                            <td class="py-3 pr-4">
                                <div class="font-medium">{{ $lead->name }}</div>
                                <flux:link href="mailto:{{ $lead->email }}">{{ $lead->email }}</flux:link>
                                @if ($lead->company)
                                    <div class="text-zinc-500">{{ $lead->company }}</div>
                                @endif
                            </td>
                            <td class="py-3 pr-4 max-w-md whitespace-pre-line">{{ $lead->message }}</td>
                            <td class="py-3 pr-4 whitespace-nowrap">
                                @if ($lead->utm_source || $lead->utm_medium)
                                    {{ collect([$lead->utm_source, $lead->utm_medium, $lead->utm_content])->filter()->implode(' / ') }}
                                    @if ($lead->shortLink)
                                        <div class="text-zinc-500">/go/{{ $lead->shortLink->code }}</div>
                                    @endif
                                @else
                                    <span class="text-zinc-500">Direct</span>
                                @endif
                            </td>
                            <td class="py-3">{{ $lead->utm_campaign ?? '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-zinc-500">No leads yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $leads->links() }}
    </div>
    @endvolt
</x-layouts.app>
