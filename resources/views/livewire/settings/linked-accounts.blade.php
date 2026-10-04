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
        $this->linkCodeExpiresAt = now()->addMinutes(LinkCode::MINUTES)->toIso8601String();
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

    @foreach ($this->chatLinkable as $provider)
        <div class="space-y-2" wire:key="chat-link-{{ $provider->value }}">
            @if ($linkCode)
                <flux:text>
                    {{ __('Type this in :provider chat within :minutes minutes:', ['provider' => $provider->label(), 'minutes' => \App\Models\LinkCode::MINUTES]) }}
                </flux:text>
                <p class="font-mono text-2xl tracking-widest select-all" data-link-code>!link {{ $linkCode }}</p>
                <flux:text class="text-sm text-zinc-500">
                    {{ __('It works once, from the account you want to link. Refresh this page after typing it.') }}
                </flux:text>
            @else
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
