<?php

use App\Enums\ContentIdStatus;
use App\Models\Track;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    // The track being edited, or null when the form creates one.
    #[Locked]
    public ?int $editingId = null;

    public string $title = '';
    public string $artist = '';
    public string $attribution = '';
    public string $contentIdStatus = 'not_registered';
    public bool $streamSafe = false;
    public ?int $durationSeconds = null;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** @var TemporaryUploadedFile|null */
    public $stems = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['required', 'string', 'max:255'],
            'attribution' => ['nullable', 'string', 'max:2000'],
            'contentIdStatus' => ['required', Rule::enum(ContentIdStatus::class)],
            // The Content ID rule: a registered track that is not allow-listed
            // would get every stream that plays it claimed.
            'streamSafe' => ['boolean', 'declined_if:contentIdStatus,'.ContentIdStatus::Registered->value],
            'durationSeconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'file' => [$this->editingId === null ? 'required' : 'nullable', 'file', 'mimetypes:audio/*'],
            'stems' => ['nullable', 'file', 'mimes:zip'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'streamSafe.declined_if' => 'A track registered with Content ID cannot be stream-safe. Allow-list all four channels with the distributor first, then mark it allow-listed.',
            'file.mimetypes' => 'The track must be an audio file.',
            'stems.mimes' => 'Stems must be a single .zip file.',
        ];
    }

    // Livewire actions are public endpoints: each one authorises itself.
    public function save(): void
    {
        $track = $this->editingId === null ? null : Track::findOrFail($this->editingId);
        $this->authorize($track === null ? 'create' : 'update', $track ?? Track::class);

        $this->validate();

        $disk = config('music.disk');
        $replaced = [];
        $track ??= new Track;

        $track->fill([
            'title' => $this->title,
            'artist' => $this->artist,
            'attribution' => trim($this->attribution) ?: null,
            'content_id_status' => $this->contentIdStatus,
            'stream_safe' => $this->streamSafe,
            'duration_seconds' => $this->durationSeconds,
        ]);

        if ($this->file) {
            $replaced[] = $track->file_path;
            $track->file_path = $this->file->store('tracks', $disk);
        }
        if ($this->stems) {
            $replaced[] = $track->stems_path;
            $track->stems_path = $this->stems->store('stems', $disk);
        }

        $track->save();

        $old = array_filter($replaced);
        if ($old !== []) {
            Storage::disk($disk)->delete($old);
        }

        $this->resetForm();
    }

    public function edit(int $trackId): void
    {
        $track = Track::findOrFail($trackId);
        $this->authorize('update', $track);

        $this->resetForm();
        $this->editingId = $track->id;
        $this->title = $track->title;
        $this->artist = $track->artist;
        $this->attribution = (string) $track->attribution;
        $this->contentIdStatus = $track->content_id_status->value;
        $this->streamSafe = $track->stream_safe;
        $this->durationSeconds = $track->duration_seconds;
    }

    public function removeStems(): void
    {
        $track = Track::findOrFail($this->editingId);
        $this->authorize('update', $track);

        if ($track->stems_path !== null) {
            Storage::disk(config('music.disk'))->delete($track->stems_path);
            $track->update(['stems_path' => null]);
        }
    }

    public function delete(int $trackId): void
    {
        $track = Track::findOrFail($trackId);
        $this->authorize('delete', $track);

        Storage::disk(config('music.disk'))->delete(array_filter([$track->file_path, $track->stems_path]));
        $track->delete();

        if ($this->editingId === $trackId) {
            $this->resetForm();
        }
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'title', 'artist', 'attribution', 'contentIdStatus', 'streamSafe', 'durationSeconds', 'file', 'stems');
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'tracks' => Track::orderBy('artist')->orderBy('title')->get(),
            'statuses' => ContentIdStatus::cases(),
            'editing' => $this->editingId === null ? null : Track::find($this->editingId),
        ];
    }
}; ?>

<div class="space-y-10">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Music catalogue</flux:heading>
        <flux:link href="{{ route('music.index') }}">View the stream-safe pack</flux:link>
    </div>

    <flux:callout variant="warning" icon="exclamation-triangle" heading="Content ID">
        <flux:callout.text>
            If any track is registered with Content ID, allow-list all four channels with the distributor first. Otherwise our own streams get claimed.
            A track registered with Content ID cannot be marked stream-safe until it is allow-listed.
        </flux:callout.text>
    </flux:callout>

    <section class="space-y-4">
        <flux:heading size="lg">{{ $editing ? 'Edit "'.$editing->title.'"' : 'Add a track' }}</flux:heading>

        <form wire:submit="save" class="grid max-w-2xl gap-4">
            <flux:input wire:model="title" label="Title" />
            <flux:input wire:model="artist" label="Artist / credits" />
            <flux:input type="number" wire:model="durationSeconds" label="Duration (seconds)" class="max-w-40" />
            <flux:textarea wire:model="attribution" label="Attribution text" rows="2"
                description="Shown on the stream-safe pack for other creators to credit. Leave blank to use &quot;Title&quot; by Artist. A link back to ARE is always added." />

            <flux:select wire:model.live="contentIdStatus" label="Content ID status">
                @foreach ($statuses as $status)
                    <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:field variant="inline">
                <flux:checkbox wire:model="streamSafe" />
                <flux:label>Stream-safe (listed in the public pack and requestable on stream)</flux:label>
                <flux:error name="streamSafe" />
            </flux:field>
            @if ($contentIdStatus === 'registered')
                <flux:text class="text-amber-600 dark:text-amber-400">This track is registered with Content ID and not allow-listed, so it cannot be stream-safe.</flux:text>
            @endif

            <flux:input type="file" wire:model="file" label="{{ $editing ? 'Replace audio file (optional)' : 'Audio file' }}" accept="audio/*" />
            <flux:input type="file" wire:model="stems" label="{{ $editing?->stems_path ? 'Replace stems (.zip, optional)' : 'Stems (.zip, optional)' }}" accept=".zip,application/zip" />
            @if ($editing?->stems_path)
                <div>
                    <flux:button size="sm" variant="ghost" wire:click="removeStems" wire:confirm="Delete the stems for this track?">Remove current stems</flux:button>
                </div>
            @endif

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ $editing ? 'Save changes' : 'Add track' }}</flux:button>
                @if ($editing)
                    <flux:button wire:click="cancel">Cancel</flux:button>
                @endif
            </div>
        </form>
    </section>

    <section class="space-y-3">
        <flux:heading size="lg">Tracks</flux:heading>
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($tracks as $track)
                <li class="py-2 flex flex-wrap items-center gap-3" wire:key="track-{{ $track->id }}">
                    <span class="flex-1">
                        <span class="font-medium">{{ $track->title }}</span>
                        <span class="text-zinc-500">· {{ $track->artist }}</span>
                    </span>
                    <span class="tabular-nums text-zinc-500 w-14">{{ $track->formattedDuration() }}</span>
                    @if ($track->stems_path)
                        <flux:badge size="sm">Stems</flux:badge>
                    @endif
                    <flux:badge size="sm" :color="$track->content_id_status === App\Enums\ContentIdStatus::Registered ? 'amber' : 'zinc'">{{ $track->content_id_status->label() }}</flux:badge>
                    @if ($track->isStreamSafe())
                        <flux:badge size="sm" color="green">Stream-safe</flux:badge>
                    @endif
                    <flux:button size="sm" wire:click="edit({{ $track->id }})">Edit</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="delete({{ $track->id }})" wire:confirm="Delete {{ $track->title }} and its files?">Delete</flux:button>
                </li>
            @empty
                <li class="py-2 text-zinc-500">No tracks yet.</li>
            @endforelse
        </ul>
    </section>
</div>
