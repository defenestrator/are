@php
    /** @var \App\Analytics\AttributionReport $report */
    /** @var list<\App\Analytics\StreamMetrics> $streams */
    /** @var list<\App\Analytics\YouTubeChannelReport> $youtube */
    /** @var list<\App\Analytics\StreamSegments> $segments */
    /** @var int $undatedVotes */
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

        <section class="space-y-3">
            <flux:heading size="lg">Twitch streams</flux:heading>
            <flux:text class="text-sm">
                Streams that started in this range. <strong>Viewers</strong> are concurrent viewers from Twitch, sampled every few
                minutes while live. <strong>Unique chatters</strong> counts each person who chatted once (not the broadcaster).
                <strong>Participation</strong> is unique chatters ÷ average viewers: an approximation that reads high, because
                more people watch than the average at any moment. Use it to compare streams. Clicks and enquiries are this
                stream's short links on every channel.
            </flux:text>

            @if ($streams === [])
                <flux:text class="text-sm text-zinc-500">No Twitch streams started in this range.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                            <tr>
                                <th scope="col" class="py-2 pe-4 font-medium">Stream</th>
                                <th scope="col" class="py-2 pe-4 font-medium">Started</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Avg viewers</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Peak viewers</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Unique chatters</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Participation (approx.)</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Short-link clicks</th>
                                <th scope="col" class="py-2 font-medium text-right">Enquiries</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($streams as $metrics)
                                @php($attributed = $report->forStream($metrics->stream()))
                                <tr>
                                    <td class="py-2 pe-4 font-mono">{{ $metrics->stream() }}</td>
                                    <td class="py-2 pe-4 tabular-nums whitespace-nowrap">{{ $metrics->session->started_at->format('D j M H:i') }}{{ $metrics->session->ended_at === null ? ' (live)' : '' }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ $metrics->averageLabel() }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ $metrics->peakLabel() }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($metrics->uniqueChatters) }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ $metrics->participationLabel() }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($attributed->clicks) }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ number_format($attributed->leads) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">YouTube channels</flux:heading>
            <flux:text class="text-sm">
                From YouTube Analytics, for every video on the channel, not just streams. <strong>Views</strong> are the views
                YouTube displays; <strong>engaged views</strong> count only plays past the first frame. <strong>Avg view
                duration</strong> is watch time ÷ views. <strong>Views from subscribers</strong> are views by people subscribed
                at the time; it is not returning viewers, which YouTube's API does not report. YouTube reports a day or more
                late, so the range a row covers can end before the range you chose.
            </flux:text>

            @if ($youtube === [])
                <flux:text class="text-sm text-zinc-500">No YouTube channel is connected for analytics. The channel's owner connects it at <flux:link href="{{ route('youtube.broadcaster.connect') }}">{{ route('youtube.broadcaster.connect', absolute: false) }}</flux:link>, signed in to ARE as a broadcaster.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                            <tr>
                                <th scope="col" class="py-2 pe-4 font-medium">Channel</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Views</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Engaged views</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Avg view duration</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Views from subscribers</th>
                                <th scope="col" class="py-2 pe-4 font-medium text-right">Live-stream views</th>
                                <th scope="col" class="py-2 font-medium">Covers</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($youtube as $channel)
                                <tr>
                                    <td class="py-2 pe-4">{{ $channel->title }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($channel->views) }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($channel->engagedViews) }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ $channel->averageViewDurationLabel() }}</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($channel->subscriberViews) }} ({{ $channel->subscriberShareLabel() }})</td>
                                    <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($channel->liveStreamViews) }}</td>
                                    <td class="py-2 {{ $channel->isPartial() ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $channel->coverageLabel() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">On ARE, by stream and topic</flux:heading>
            <flux:text class="text-sm">
                What the audience did on ARE during each stream that started in this range, split by the topic that was set
                (a stretch with no topic is its own row). Questions are by where they were asked; votes are first votes on a
                question, not changes; bus counts are chat ballots, actions published to the game, published actions vetoed,
                and free-text actions a moderator approved. Topics and the queue are shared by every channel, so when two
                channels stream at once their rows show the same activity. <strong>Driven by</strong> is human for every
                segment until the VTuber bridge lands.
            </flux:text>

            @if ($segments === [])
                <flux:text class="text-sm text-zinc-500">No streams started in this range.</flux:text>
            @else
                @foreach ($segments as $stream)
                    <div class="space-y-2">
                        <flux:heading size="md" class="font-mono">{{ $stream->stream() }}</flux:heading>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                                    <tr>
                                        <th scope="col" class="py-2 pe-4 font-medium">Topic</th>
                                        <th scope="col" class="py-2 pe-4 font-medium">Time</th>
                                        <th scope="col" class="py-2 pe-4 font-medium">Questions</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Votes</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Bus ballots</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Published</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Vetoed</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Approved</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Song requests</th>
                                        <th scope="col" class="py-2 pe-4 font-medium text-right">Clips marked</th>
                                        <th scope="col" class="py-2 font-medium">Driven by</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                    @foreach ($stream->segments as $segment)
                                        @php($c = $segment['counts'])
                                        <tr>
                                            <td class="py-2 pe-4">{{ $segment['topic'] ?? '(no topic)' }}</td>
                                            <td class="py-2 pe-4 tabular-nums whitespace-nowrap">{{ $segment['from']->format('D H:i') }}–{{ $segment['until']->format('H:i') }}</td>
                                            <td class="py-2 pe-4">{{ $c->totalQuestions() }}@if ($c->totalQuestions() > 0) <span class="text-zinc-500">({{ $c->questionsLabel() }})</span>@endif</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->votes) }}</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->ballots) }}</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->published) }}</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->vetoed) }}</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->approved) }}</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->songRequests) }}</td>
                                            <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($c->clipsMarked) }}</td>
                                            <td class="py-2">{{ $segment['driver'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @php($t = $stream->total)
                                <tfoot class="border-t-2 border-zinc-300 dark:border-zinc-600 font-semibold">
                                    <tr>
                                        <th scope="row" class="py-2 pe-4 text-left" colspan="2">Stream total</th>
                                        <td class="py-2 pe-4">{{ $t->totalQuestions() }}@if ($t->totalQuestions() > 0) <span class="font-normal text-zinc-500">({{ $t->questionsLabel() }})</span>@endif</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->votes) }}</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->ballots) }}</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->published) }}</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->vetoed) }}</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->approved) }}</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->songRequests) }}</td>
                                        <td class="py-2 pe-4 text-right tabular-nums">{{ number_format($t->clipsMarked) }}</td>
                                        <td class="py-2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endif

            @if ($undatedVotes > 0)
                <flux:text class="text-sm text-zinc-500">
                    {{ number_format($undatedVotes) }} earlier {{ \Illuminate\Support\Str::plural('vote', $undatedVotes) }} {{ $undatedVotes === 1 ? 'was' : 'were' }} cast before votes were dated, so {{ $undatedVotes === 1 ? 'it is' : 'they are' }} in no segment.
                </flux:text>
            @endif
        </section>

        @if ($report->undatedClicks > 0)
            <flux:text class="text-sm text-zinc-500">
                {{ number_format($report->undatedClicks) }} earlier short-link {{ \Illuminate\Support\Str::plural('click', $report->undatedClicks) }} {{ $report->undatedClicks === 1 ? 'was' : 'were' }} counted before clicks were dated, so {{ $report->undatedClicks === 1 ? 'it is' : 'they are' }} not in any range.
            </flux:text>
        @endif
    </div>
</x-layouts.app>
