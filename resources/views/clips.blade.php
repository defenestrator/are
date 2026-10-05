<?php

use App\Clips\ClipReview;
use App\Clips\ClipStorage;
use App\Clips\ClipReviewStatus;
use App\Clips\StreamMarkerStatus;
use App\Models\StreamMarker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public const PER_PAGE = 25;

    /** review = ready clips still to decide; approved; rejected; all = every !clip. */
    #[Url]
    public string $show = 'review';

    public ?int $editing = null;

    public string $title = '';

    public string $trimStart = '';

    public string $trimEnd = '';

    public ?int $rejecting = null;

    public string $rejectNote = '';

    // Every Livewire request (paging and each action included) is a public
    // endpoint, so the gate is checked on each one, not only by the route.
    public function boot(): void
    {
        $this->authorize('moderate');
    }

    public function updatedShow(): void
    {
        $this->resetPage();
        $this->cancel();
    }

    public function approve(int $id): void
    {
        ClipReview::approve($this->marker($id), Auth::user());
        $this->cancel();
    }

    public function startReject(int $id): void
    {
        $this->cancel();
        $this->rejecting = $this->marker($id)->id;
    }

    public function reject(): void
    {
        if ($this->rejecting === null) {
            return;
        }

        ClipReview::reject($this->marker($this->rejecting), Auth::user(), $this->rejectNote);
        $this->cancel();
    }

    public function edit(int $id): void
    {
        $this->cancel();
        $marker = $this->marker($id);

        $this->editing = $marker->id;
        $this->title = (string) ($marker->title ?? $marker->description ?? '');
        $this->trimStart = (string) ($marker->trim_start_seconds ?? 0);
        $this->trimEnd = (string) ($marker->trim_end_seconds ?? $marker->clipDuration());
    }

    public function save(): void
    {
        if ($this->editing === null) {
            return;
        }

        ClipReview::edit($this->marker($this->editing), Auth::user(), $this->title, $this->trimStart, $this->trimEnd);
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->reset('editing', 'title', 'trimStart', 'trimEnd', 'rejecting', 'rejectNote');
        $this->resetValidation();
    }

    private function marker(int $id): StreamMarker
    {
        return StreamMarker::findOrFail($id);
    }

    public function with(): array
    {
        $query = StreamMarker::with(['creator', 'reviewer', 'streamSession'])->latest('id');

        match ($this->show) {
            'approved' => $query->where('status', StreamMarkerStatus::ClipReady)->where('review_status', ClipReviewStatus::Approved),
            'rejected' => $query->where('status', StreamMarkerStatus::ClipReady)->where('review_status', ClipReviewStatus::Rejected),
            'all' => $query,
            default => $query->where('status', StreamMarkerStatus::ClipReady)->where('review_status', ClipReviewStatus::Pending),
        };

        return ['markers' => $query->paginate(self::PER_PAGE), 'storage' => ClipStorage::usage()];
    }
}; ?>

<x-layouts.app>
    @volt('clips')
    <div class="space-y-6">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Clips</flux:heading>
            <flux:text>Every <code>!clip</code> from chat and the clip Twitch cut around it. Approve, edit the title and trim, or reject. <strong>Approving publishes nothing</strong>: uploading approved clips is a later step.</flux:text>
        </div>

        <flux:text size="sm" data-test="clip-storage">
            Clip files: {{ Number::fileSize($storage['bytes'], 1) }} in {{ $storage['clips'] }} {{ Str::plural('clip', $storage['clips']) }}{{ $storage['free_bytes'] !== null ? ', '.Number::fileSize($storage['free_bytes'], 1).' free on the disk' : '' }}.
            Files of rejected clips are deleted after {{ config('clips.keep_rejected_days') }} days, and of approved clips not yet published after {{ config('clips.keep_approved_days') }}. Clips to review are kept.
        </flux:text>

        <flux:radio.group wire:model.live="show" variant="segmented" size="sm">
            <flux:radio value="review" label="To review" />
            <flux:radio value="approved" label="Approved" />
            <flux:radio value="rejected" label="Rejected" />
            <flux:radio value="all" label="All markers" />
        </flux:radio.group>

        @error('clip')
            <flux:text class="text-red-600">{{ $message }}</flux:text>
        @enderror

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Marked</th>
                        <th class="py-2 pr-4 font-medium">Clip</th>
                        <th class="py-2 pr-4 font-medium">Title and trim</th>
                        <th class="py-2 pr-4 font-medium">Status</th>
                        <th class="py-2 font-medium">Decision</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($markers as $marker)
                        <tr wire:key="marker-{{ $marker->id }}" class="align-top">
                            <td class="py-3 pr-4 whitespace-nowrap tabular-nums">
                                <div class="text-zinc-500">{{ $marker->created_at?->format('Y-m-d H:i:s') }}</div>
                                @if ($marker->position())
                                    <div>at {{ $marker->position() }}</div>
                                @endif
                                <div class="text-zinc-500">{{ $marker->creator?->name ?? '' }}</div>
                            </td>
                            <td class="py-3 pr-4">
                                @if ($marker->files_pruned_at)
                                    <div class="text-zinc-500">File deleted {{ $marker->files_pruned_at->diffForHumans() }} under the retention rules.</div>
                                @elseif ($marker->fetched_at)
                                    @foreach (StreamMarker::VARIANTS as $variant)
                                        @if ($marker->filePath($variant))
                                            <video controls preload="metadata" class="mb-2 max-h-48 rounded" src="{{ route('clips.file', ['marker' => $marker->id, 'variant' => $variant]) }}" aria-label="{{ ucfirst($variant) }} clip"></video>
                                        @endif
                                    @endforeach
                                    <div class="text-zinc-500">Saved, {{ number_format(($marker->file_bytes ?? 0) / 1048576, 1) }} MB</div>
                                @elseif ($marker->status === StreamMarkerStatus::ClipReady)
                                    <div class="text-zinc-500">{{ $marker->fetch_error ? 'Download failed: '.$marker->fetch_error : 'Downloading the file...' }}</div>
                                @endif
                                @if ($marker->clip_edit_url)
                                    <flux:link href="{{ $marker->clip_edit_url }}" target="_blank" rel="noopener">On Twitch</flux:link>
                                @endif
                            </td>
                            <td class="py-3 pr-4 max-w-md">
                                @if ($editing === $marker->id)
                                    <form wire:submit="save" class="space-y-2">
                                        <flux:input wire:model="title" label="Title" maxlength="{{ ClipReview::TITLE_MAX }}" />
                                        <div class="flex gap-2">
                                            <flux:input wire:model="trimStart" type="number" step="0.1" min="0" max="{{ $marker->clipDuration() }}" label="In (s)" />
                                            <flux:input wire:model="trimEnd" type="number" step="0.1" min="0" max="{{ $marker->clipDuration() }}" label="Out (s)" />
                                        </div>
                                        <flux:text size="sm">The clip is {{ $marker->clipDuration() }} s long. Keep at least {{ ClipReview::MIN_TRIMMED_SECONDS }} s.</flux:text>
                                        <div class="flex gap-2">
                                            <flux:button type="submit" size="sm" variant="primary">Save</flux:button>
                                            <flux:button type="button" size="sm" wire:click="cancel">Cancel</flux:button>
                                        </div>
                                    </form>
                                @else
                                    <div class="font-medium">{{ $marker->title ?? $marker->description }}</div>
                                    @if ($marker->trim_start_seconds !== null)
                                        <div class="text-zinc-500 tabular-nums">Trim {{ $marker->trim_start_seconds }} s to {{ $marker->trim_end_seconds }} s</div>
                                    @endif
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <flux:badge size="sm" :color="$marker->status->color()">{{ $marker->status->label() }}</flux:badge>
                                @if ($marker->error)
                                    <div class="mt-1 max-w-sm text-zinc-500">{{ $marker->error }}</div>
                                @endif
                            </td>
                            <td class="py-3">
                                @if ($marker->status === StreamMarkerStatus::ClipReady)
                                    <flux:badge size="sm" :color="$marker->review_status->color()">{{ $marker->review_status->label() }}</flux:badge>
                                    @if ($marker->reviewer)
                                        <div class="text-zinc-500">by {{ $marker->reviewer->name }}</div>
                                    @endif

                                    @if ($rejecting === $marker->id)
                                        <form wire:submit="reject" class="mt-2 space-y-2">
                                            <flux:input wire:model="rejectNote" label="Why? (optional)" maxlength="500" />
                                            <div class="flex gap-2">
                                                <flux:button type="submit" size="sm" variant="danger">Reject</flux:button>
                                                <flux:button type="button" size="sm" wire:click="cancel">Cancel</flux:button>
                                            </div>
                                        </form>
                                    @elseif ($editing !== $marker->id)
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @if ($marker->review_status !== ClipReviewStatus::Approved)
                                                <flux:button size="sm" variant="primary" wire:click="approve({{ $marker->id }})">Approve</flux:button>
                                            @endif
                                            <flux:button size="sm" wire:click="edit({{ $marker->id }})">Edit</flux:button>
                                            @if ($marker->review_status !== ClipReviewStatus::Rejected)
                                                <flux:button size="sm" variant="danger" wire:click="startReject({{ $marker->id }})">Reject</flux:button>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-zinc-500">
                                {{ $show === 'review' ? 'Nothing to review. A moderator can type !clip in chat during a stream.' : 'No clips here.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $markers->links() }}
    </div>
    @endvolt
</x-layouts.app>
