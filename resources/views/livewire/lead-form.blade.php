<?php

use App\Models\Lead;
use App\Models\ShortLink;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {
    /** Accepted enquiries per IP address per hour. */
    public const MAX_PER_HOUR = 5;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:255')]
    public string $company = '';

    #[Validate('required|string|min:10|max:5000')]
    public string $message = '';

    #[Validate('accepted', message: 'Please tick the box so we can store your enquiry and reply to it.')]
    public bool $consent = false;

    /** Honeypot: hidden from people, filled in by naive bots. */
    public string $website = '';

    public bool $submitted = false;

    public function submit(): void
    {
        // Bots that fill the honeypot get the same thank-you, and nothing is stored.
        if ($this->website !== '') {
            $this->finish();

            return;
        }

        $validated = $this->validate();

        $key = 'lead-form:'.sha1((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            $this->addError('form', __('about.form.throttled', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ]));

            return;
        }

        RateLimiter::hit($key, 3600);

        $attribution = ShortLink::attribution();

        Lead::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'] !== '' ? $validated['company'] : null,
            'message' => $validated['message'],
            'consented_at' => now(),
            'short_link_id' => ShortLink::whereKey($attribution['short_link_id'] ?? null)->value('id'),
            'utm_source' => $attribution['utm_source'] ?? null,
            'utm_medium' => $attribution['utm_medium'] ?? null,
            'utm_campaign' => $attribution['utm_campaign'] ?? null,
            'utm_content' => $attribution['utm_content'] ?? null,
        ]);

        $this->finish();
    }

    private function finish(): void
    {
        $this->reset('name', 'email', 'company', 'message', 'consent', 'website');
        $this->submitted = true;
    }
}; ?>

<div>
    @if ($submitted)
        <flux:callout icon="check-circle" variant="success">
            <flux:callout.heading>{{ __('about.form.thanks_heading') }}</flux:callout.heading>
            <flux:callout.text>{{ __('about.form.thanks_body') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="submit" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('about.form.name')" required autocomplete="name" />
            <flux:input wire:model="email" :label="__('about.form.email')" type="email" required autocomplete="email" />
            <flux:input wire:model="company" :label="__('about.form.company')" autocomplete="organization" />
            <flux:textarea wire:model="message" :label="__('about.form.message')" rows="5" required />

            <div aria-hidden="true" class="absolute -left-[10000px] h-px w-px overflow-hidden">
                <label for="lead-website">Website</label>
                <input id="lead-website" type="text" wire:model="website" tabindex="-1" autocomplete="off" />
            </div>

            <flux:field variant="inline">
                <flux:checkbox wire:model="consent" />
                <flux:label>{{ __('about.form.consent') }}</flux:label>
                <flux:error name="consent" />
            </flux:field>
            <flux:text size="sm">{{ __('about.form.consent_note') }}</flux:text>

            <flux:error name="form" />

            <div>
                <flux:button type="submit" variant="primary">{{ __('about.form.submit') }}</flux:button>
            </div>
        </form>
    @endif
</div>
