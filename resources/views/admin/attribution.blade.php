@php
    /** @var \App\Analytics\AttributionReport $report */
    $range = $report->range;
    $query = $range->preset
        ? ['preset' => $range->preset]
        : ['from' => $range->from->toDateString(), 'to' => $range->lastDay()->toDateString()];
    $totals = $report->totals();
@endphp

<x-layouts.app>
    <div class="space-y-10">
        <header class="space-y-2">
            <flux:heading size="xl" level="1">Attribution</flux:heading>
            <flux:text>Which show, on which channel, produced leads: {{ $range->label() }} ({{ config('app.timezone') }}).</flux:text>
        </header>

        <section class="flex flex-wrap items-end gap-4">
            <nav class="flex flex-wrap gap-2" aria-label="Date range presets">
                @foreach (\App\Analytics\DateRange::PRESETS as $key => $label)
                    <flux:button size="sm" :variant="$range->preset === $key ? 'primary' : 'outline'" href="{{ route('admin.attribution', ['preset' => $key]) }}">
                        {{ $label }}
                    </flux:button>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('admin.attribution') }}" class="flex flex-wrap items-end gap-2">
                <flux:input type="date" name="from" label="From" :value="$range->from->toDateString()" size="sm" />
                <flux:input type="date" name="to" label="To" :value="$range->lastDay()->toDateString()" size="sm" />
                <flux:button type="submit" size="sm">Show</flux:button>
            </form>

            <flux:spacer />

            <flux:button size="sm" icon="arrow-down-tray" href="{{ route('admin.attribution.export', $query) }}">
                Download CSV
            </flux:button>
        </section>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>{{ $errors->first() }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:text class="text-sm">
            <strong>Short-link clicks</strong> are visits to an ARE short link (<code>/go/…</code>) in this range.
            <strong>Enquiries with consent</strong> are Professional Services enquiries from the /about form in this range,
            credited to the last short link that browser clicked. Conversion is enquiries ÷ clicks in the same range, so an
            enquiry made days after its click can push one range over 100%. Orkestera sign-ups are not tracked here yet.
        </flux:text>

        @if ($report->isEmpty())
            <flux:callout icon="information-circle">
                <flux:callout.heading>No short-link clicks or enquiries in this range.</flux:callout.heading>
                <flux:callout.text>Put a short link on stream (an overlay or chat command) and clicks will show up here.</flux:callout.text>
            </flux:callout>
        @else
            <section class="space-y-3">
                <flux:heading size="lg">By channel</flux:heading>
                @include('admin.partials.attribution-table', ['rows' => $report->channels(), 'withStream' => false, 'totals' => $totals])
            </section>

            <section class="space-y-3">
                <flux:heading size="lg">By stream</flux:heading>
                @include('admin.partials.attribution-table', ['rows' => $report->streams, 'withStream' => true, 'totals' => $totals])
            </section>
        @endif

        @if ($report->undatedClicks > 0)
            <flux:text class="text-sm text-zinc-500">
                {{ number_format($report->undatedClicks) }} earlier short-link {{ \Illuminate\Support\Str::plural('click', $report->undatedClicks) }} {{ $report->undatedClicks === 1 ? 'was' : 'were' }} counted before clicks were dated, so {{ $report->undatedClicks === 1 ? 'it is' : 'they are' }} not in any range.
            </flux:text>
        @endif
    </div>
</x-layouts.app>
