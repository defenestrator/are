<?php

use App\Models\StreamMarker;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public const PER_PAGE = 25;

    // Every Livewire request (paging included) is a public endpoint, so the
    // gate is checked on each one, not only by the route middleware.
    public function boot(): void
    {
        $this->authorize('moderate');
    }

    public function with(): array
    {
        return [
            'markers' => StreamMarker::with(['creator', 'streamSession'])->latest('id')->paginate(self::PER_PAGE),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('clips')
    <div class="space-y-6">
        <div>
            <flux:heading size="xl" level="1">Clips</flux:heading>
            <flux:text>Every <code>!clip</code> from chat, newest first: the stream marker and the clip Twitch cut around it. Approving and publishing come later.</flux:text>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Marked</th>
                        <th class="py-2 pr-4 font-medium">Stream position</th>
                        <th class="py-2 pr-4 font-medium">Note</th>
                        <th class="py-2 pr-4 font-medium">By</th>
                        <th class="py-2 pr-4 font-medium">Status</th>
                        <th class="py-2 font-medium">Clip</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($markers as $marker)
                        <tr wire:key="marker-{{ $marker->id }}" class="align-top">
                            <td class="py-3 pr-4 whitespace-nowrap tabular-nums text-zinc-500">
                                {{ $marker->created_at?->format('Y-m-d H:i:s') }}
                            </td>
                            <td class="py-3 pr-4 whitespace-nowrap tabular-nums">
                                {{ $marker->position() ?? '' }}
                                @if ($marker->streamSession)
                                    <div class="text-zinc-500">Stream {{ $marker->streamSession->twitch_stream_id }}</div>
                                @endif
                            </td>
                            <td class="py-3 pr-4 max-w-md">{{ $marker->description }}</td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ $marker->creator?->name ?? '' }}</td>
                            <td class="py-3 pr-4">
                                <flux:badge size="sm" :color="$marker->status->color()">{{ $marker->status->label() }}</flux:badge>
                                @if ($marker->error)
                                    <div class="mt-1 max-w-sm text-zinc-500">{{ $marker->error }}</div>
                                @endif
                            </td>
                            <td class="py-3 whitespace-nowrap">
                                @if ($marker->clip_edit_url)
                                    <flux:link href="{{ $marker->clip_edit_url }}" target="_blank" rel="noopener">{{ $marker->clip_id }}</flux:link>
                                @elseif ($marker->clip_id)
                                    {{ $marker->clip_id }}
                                @endif
                                @if ($marker->landscape_download_url || $marker->portrait_download_url)
                                    <div class="mt-1 space-x-2">
                                        @if ($marker->downloadUrlsExpired())
                                            <span class="text-zinc-500">Download links expired {{ $marker->download_urls_expire_at?->diffForHumans() }}</span>
                                        @else
                                            @if ($marker->landscape_download_url)
                                                <flux:link href="{{ $marker->landscape_download_url }}" rel="noopener noreferrer">Landscape MP4</flux:link>
                                            @endif
                                            @if ($marker->portrait_download_url)
                                                <flux:link href="{{ $marker->portrait_download_url }}" rel="noopener noreferrer">Portrait MP4</flux:link>
                                            @endif
                                            <div class="text-zinc-500">Expires {{ $marker->download_urls_expire_at?->diffForHumans() }}</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-zinc-500">No clips yet. A moderator can type <code>!clip</code> in chat during a stream.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $markers->links() }}
    </div>
    @endvolt
</x-layouts.app>
