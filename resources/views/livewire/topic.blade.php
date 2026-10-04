<?php
use App\Models\Topic;
use App\Moderation;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public $topic = "";

    public function mount()
    {
        $this->topic = Topic::current()?->topic;
    }

    /**
     * Another moderator set or cleared the topic; show it everywhere at once.
     */
    #[On('echo:topic,TopicChanged')]
    public function topicChanged(): void
    {
        $this->topic = Topic::current()?->topic;
    }

    public function clear() {
        $this->authorize('moderate');

        Moderation::clearTopic(Auth::user());
        $this->topic = "";

        $this->dispatch("topic-changed");
    }

    public function save() {
        $this->authorize('moderate');

        $this->validate(['topic' => 'required|string|max:255']);
        Moderation::setTopic(Auth::user(), $this->topic);

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

