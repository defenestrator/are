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
        <div class="flex gap-4 max-w-xl">
            <flux:input wire:model="topic" />
            <flux:button wire:click="save"> Save </flux:button>
            <flux:button wire:click="clear"> Clear Questions </flux:button>
        </div>
    </form>
@else
{{ Topic::first()?->topic }}
@endif

</div>

