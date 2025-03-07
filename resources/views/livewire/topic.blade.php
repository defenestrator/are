<?php
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    public $topic = "";

    public function mount()
    {
        $this->topic = Topic::first()?->topic;
    }

    public function clear() {
        if (Auth::user()->isAdminUser()) {
            DB::table("questions")->delete();
            DB::table("question_votes")->delete();
            DB::table("topics")->delete();

            $this->dispatch("topic-changed");
        }
    }
    public function save() {
        if (Auth::user()->isAdminUser()) {
            DB::table("topics")->delete();
            DB::table("topics")->insert([
                "topic" => $this->topic,
            ]);

            $this->dispatch("topic-changed");
        }
    }
}

?>

<div>
@if (Auth::user()->isAdminUser())
    <form>
        <div class="flex gap-4 max-w-xl mb-2">
            <flux:input wire:model="topic" />
            <flux:button wire:click="save"> Save </flux:button>
            <flux:button wire:click="clear"> Clear </flux:button>
        </div>
    </form>
@else

@endif
@if (Topic::first())
<div class="bg-violet-100 dark:bg-violet-800 font-bold p-4 rounded-md text-zinc-900 dark:text-white">
    {{ Topic::first()?->topic }}
</div>
@endif
</div>

