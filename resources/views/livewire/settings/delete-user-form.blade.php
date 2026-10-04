<?php

use App\Events\QuestionArchived;
use App\Livewire\Actions\Logout;
use App\Models\Question;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        // A banned user's bans would survive deletion anyway (they are keyed
        // to the platform accounts too), but nobody banned should be able to
        // act here, whether or not the not-banned middleware ran.
        abort_if(Auth::user()->isBanned(), 403, 'You cannot delete your account while you are banned or timed out.');

        $user = Auth::user();
        $logout();

        // The database cascade removes their questions and votes without model
        // events, so tell open vote pages here. Both events go out after commit,
        // and a retried attempt's events are discarded with its rollback.
        DB::transaction(function () use ($user) {
            $own = $user->questions()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $votedOn = Question::active()
                ->whereIn('id', $user->votes()->select('question_id'))
                ->whereNotIn('id', $own)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Lock every open question the cascade touches, in id order, before
            // deleting: voters lock the same rows, so neither side can hold one
            // row while waiting on another the other holds.
            $locked = Question::whereKey([...$own, ...$votedOn])->orderBy('id')->lockForUpdate()->get();

            $user->delete();

            // Each total that lost a vote goes out with a fresh vote_version.
            $locked->whereIn('id', $votedOn)->each->announceVoteChange();

            if ($own !== []) {
                QuestionArchived::dispatch($own);
            }
        }, attempts: 3);

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Delete account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and all of its resources') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" x-data=""
            x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" focusable class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted') }}
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">{{ __('Delete account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
