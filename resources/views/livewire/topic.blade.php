<?php
use App\Models\Topic;
use App\Moderation;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public $topic = "";

    // The topic as last read from the database, to tell a real change from a
    // routine sync.
    #[Locked]
    public ?string $syncedTopic = null;

    public function mount()
    {
        $this->topic = $this->syncedTopic = Topic::current()?->topic;
    }

    /**
     * Sent by resources/js/live-queue.js on TopicChanged and on every fallback
     * poll (#125), instead of a Livewire echo listener. The "Prime Directive"
     * line re-reads the topic on every render. The moderator's input is
     * replaced only when the stored topic really changed, so a poll arriving
     * while a moderator types leaves their unsaved text alone.
     */
    #[On('topic-sync')]
    public function syncTopic(): void
    {
        $current = Topic::current()?->topic;

        if ($current !== $this->syncedTopic) {
            $this->topic = $this->syncedTopic = $current;
        }
    }

    public function clear() {
        $this->authorize('moderate');

        Moderation::clearTopic(Auth::user());
        $this->topic = "";
        $this->syncedTopic = null;

        $this->dispatch("topic-changed");
    }

    public function save() {
        $this->authorize('moderate');

        $this->validate(['topic' => 'required|string|max:255']);
        Moderation::setTopic(Auth::user(), $this->topic);
        $this->syncedTopic = Topic::current()?->topic;

        $this->dispatch("topic-changed");
    }
}

?>

<div>
@can('moderate')
    <form>
        <div class="flex gap-4 max-w-xl mb-2">
            <flux:input wire:model="topic" />
            <flux:button wire:click="save"> Save </flux:button>
            <flux:button wire:click="clear"> Clear </flux:button>
        </div>
    </form>
@endcan
<div class="bg-violet-100 dark:bg-violet-800 font-bold p-4 rounded-md text-zinc-900 dark:text-white">
    Prime Directive: {{ Topic::current()?->topic ?? 'Be funny.' }}
</div>

</div>

