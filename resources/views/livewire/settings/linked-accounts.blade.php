<?php

use App\Exceptions\IdentityLinkException;
use App\Identities;
use App\IdentityProvider;
use App\Models\LinkCode;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    /** The code just issued, in display form. Shown once; only its HMAC is stored. */
    #[Locked]
    public ?string $linkCode = null;

    #[Locked]
    public ?string $linkCodeExpiresAt = null;

    public function issueLinkCode(): void
    {
        $user = Auth::user();

        // Persistent middleware already signs banned users out; this keeps the
        // rule even where it does not run. Linking itself refuses them too.
        abort_if($user->isBanned(), 403);

        $this->linkCode = LinkCode::issueFor($user);
        unset($this->contestedCode);
        $this->linkCodeExpiresAt = now()->addMinutes(LinkCode::MINUTES)->toIso8601String();
    }

    /**
     * Confirm a link someone proposed by typing this user's code in chat.
     * Only this attaches the chat account.
     */
    public function confirmLink(int $linkCodeId): void
    {
        $pending = Auth::user()->linkCodes()->findOrFail($linkCodeId);

        try {
            $identity = $pending->confirm();
        } catch (IdentityLinkException $e) {
            $this->addError('identity', $e->getMessage());
            unset($this->pendingLinks);

            return;
        }

        $this->linkCode = null;
        unset($this->identities, $this->pendingLinks, $this->chatLinkable);
        session()->now('identity_status', "{$identity->provider->label()} account {$identity->name} linked.");
    }

    /**
     * Not my account: discard the pending link.
     */
    public function rejectLink(int $linkCodeId): void
    {
        Auth::user()->linkCodes()->findOrFail($linkCodeId)->reject();

        $this->linkCode = null;
        unset($this->pendingLinks);
        session()->now('identity_status', 'Link request discarded. Nothing was linked.');
    }

    /**
     * The user's code, if it was voided because more than one account typed it.
     */
    #[Computed]
    public function contestedCode(): ?LinkCode
    {
        return Auth::user()->linkCodes()->contested()->latest('id')->first();
    }

    /**
     * Links typed in chat with this user's code, waiting for them to confirm.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LinkCode>
     */
    #[Computed]
    public function pendingLinks()
    {
        return Auth::user()->linkCodes()->pending()->orderBy('id')->get();
    }

    public function unlink(int $identityId): void
    {
        $user = Auth::user();
        $identity = $user->identities()->findOrFail($identityId);

        $this->authorize('delete', $identity);

        try {
            Identities::unlink($user, $identity);
        } catch (IdentityLinkException $e) {
            $this->addError('identity', $e->getMessage());

            return;
        }

        unset($this->identities);
        session()->now('identity_status', "{$identity->provider->label()} account unlinked.");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Identity>
     */
    #[Computed]
    public function identities()
    {
        return Auth::user()->identities()->orderBy('id')->get();
    }

    /**
     * @return list<IdentityProvider>
     */
    #[Computed]
    public function linkable(): array
    {
        $linked = $this->identities->map(fn ($identity) => $identity->provider);

        return array_values(array_filter(
            IdentityProvider::signInProviders(),
            fn (IdentityProvider $provider) => ! $linked->contains($provider),
        ));
    }

    /**
     * Providers not yet linked that link by typing a code into their chat.
     *
     * @return list<IdentityProvider>
     */
    #[Computed]
    public function chatLinkable(): array
    {
        $linked = $this->identities->map(fn ($identity) => $identity->provider);

        return array_values(array_filter(
            IdentityProvider::cases(),
            fn (IdentityProvider $provider) => $provider->linksThroughChat() && ! $linked->contains($provider),
        ));
    }
}; ?>

<section class="mt-10 space-y-4">
    <div>
        <flux:heading>{{ __('Linked accounts') }}</flux:heading>
        <flux:subheading>{{ __('Sign in with any of these. Your votes and question limits count once, however you sign in.') }}</flux:subheading>
    </div>

    @if (session('identity_status'))
        <flux:text class="text-green-600 dark:text-green-400">{{ session('identity_status') }}</flux:text>
    @endif
    @if (session('identity_error'))
        <flux:text class="text-red-600 dark:text-red-400" role="alert">{{ session('identity_error') }}</flux:text>
    @endif
    <flux:error name="identity" />

    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($this->identities as $identity)
            <li class="py-2 flex items-center gap-3" wire:key="linked-{{ $identity->id }}">
                @if ($identity->avatar_url)
                    <img src="{{ $identity->avatar_url }}" alt="" class="w-8 rounded-full" />
                @endif
                <span class="flex-1">
                    <span class="font-medium">{{ $identity->provider->label() }}</span>
                    <span class="text-zinc-500">{{ $identity->name }}</span>
                </span>
                @if ($this->identities->count() > 1)
                    <flux:button size="sm" variant="ghost" wire:click="unlink({{ $identity->id }})"
                        wire:confirm="Unlink this {{ $identity->provider->label() }} account?">
                        {{ __('Unlink') }}
                    </flux:button>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($this->contestedCode)
        <div class="rounded-lg border border-red-300 p-3 dark:border-red-700" role="alert" data-link-contested>
            <flux:text class="text-red-700 dark:text-red-400">
                {{ __('Your link code was typed in chat by more than one account, so it was cancelled and nothing was linked. Someone may have copied it from chat. Get a new code and type it again.') }}
            </flux:text>
        </div>
    @endif

    @foreach ($this->pendingLinks as $pending)
        <div class="space-y-2 rounded-lg border border-amber-300 p-3 dark:border-amber-700" wire:key="pending-link-{{ $pending->id }}">
            <flux:text>
                {{ __('Link :provider channel', ['provider' => $pending->pending_provider->label()]) }}
                <span class="font-semibold" data-pending-name>{{ $pending->pending_name }}</span>?
            </flux:text>
            {{-- Display names are not unique and can be look-alikes; the id is what identifies the account. --}}
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-zinc-500">{{ __(':provider channel id', ['provider' => $pending->pending_provider->label()]) }}</dt>
                <dd class="font-mono text-base font-semibold break-all" data-pending-id>{{ $pending->pending_provider_user_id }}</dd>
                <dt class="text-zinc-500">{{ __('Typed at') }}</dt>
                <dd data-pending-claimed-at>{{ $pending->claimed_at->toDateTimeString() }} UTC</dd>
            </dl>
            <flux:text class="text-sm text-zinc-500">
                {{ __('Someone typed your code in chat from this account. Check that the channel id is yours before you confirm.') }}
            </flux:text>
            <div class="flex gap-2">
                <flux:button size="sm" variant="primary" wire:click="confirmLink({{ $pending->id }})">{{ __('Yes, link it') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="rejectLink({{ $pending->id }})">{{ __('Not mine') }}</flux:button>
            </div>
        </div>
    @endforeach

    @foreach ($this->chatLinkable as $provider)
        <div class="space-y-2" wire:key="chat-link-{{ $provider->value }}">
            @if ($linkCode && $this->pendingLinks->isEmpty() && ! $this->contestedCode)
                <div wire:poll.5s></div>
                <flux:text>
                    {{ __('Type this in :provider chat within :minutes minutes:', ['provider' => $provider->label(), 'minutes' => \App\Models\LinkCode::MINUTES]) }}
                </flux:text>
                <p class="font-mono text-2xl tracking-widest select-all" data-link-code>!link {{ $linkCode }}</p>
                <flux:text class="text-sm text-zinc-500">
                    {{ __('It works once, from the account you want to link. You will be asked to confirm the account here.') }}
                </flux:text>
            @elseif ($this->pendingLinks->isEmpty())
                <flux:button size="sm" wire:click="issueLinkCode">
                    {{ __('Link :provider', ['provider' => $provider->label()]) }}
                </flux:button>
            @endif
        </div>
    @endforeach

    @if ($this->linkable)
        <div class="flex flex-wrap gap-2">
            @foreach ($this->linkable as $provider)
                <flux:button size="sm" href="{{ route('identities.link', $provider) }}">
                    {{ __('Link :provider', ['provider' => $provider->label()]) }}
                </flux:button>
            @endforeach
        </div>
    @endif
</section>
