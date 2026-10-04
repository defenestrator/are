<x-layouts.public :title="__('about.title')">
    <div class="mx-auto flex max-w-2xl flex-col gap-10 py-6">
        <section id="who-we-are" class="flex flex-col gap-3">
            <flux:heading size="xl" level="1">{{ __('about.who.heading') }}</flux:heading>
            @foreach (__('about.who.body') as $paragraph)
                <flux:text>{{ $paragraph }}</flux:text>
            @endforeach
        </section>

        <flux:separator variant="subtle" />

        <section id="orkestera" class="flex flex-col gap-3">
            <flux:heading size="lg" level="2">{{ __('about.orkestera.heading') }}</flux:heading>
            @foreach (__('about.orkestera.body') as $paragraph)
                <flux:text>{{ $paragraph }}</flux:text>
            @endforeach
            <flux:link href="{{ __('about.orkestera.link_url') }}" rel="noopener">{{ __('about.orkestera.link_label') }}</flux:link>
        </section>

        <flux:separator variant="subtle" />

        <section id="work-with-us" class="flex flex-col gap-3">
            <flux:heading size="lg" level="2">{{ __('about.work.heading') }}</flux:heading>
            @foreach (__('about.work.body') as $paragraph)
                <flux:text>{{ $paragraph }}</flux:text>
            @endforeach

            <livewire:lead-form />
        </section>
    </div>
</x-layouts.public>
