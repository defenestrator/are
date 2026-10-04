<?php

namespace App\Notifications;

use App\Analytics\AttributionReport;
use App\Analytics\AttributionRow;
use App\Analytics\StreamMetrics;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Notifications\Notification;

/**
 * Last week's attribution as one Slack or Discord message (#12): totals,
 * the top stream, clicks, enquiries and conversion by channel and by
 * stream, each Twitch stream's audience (average and peak viewers, unique
 * chatters, approximate participation), and a link to /admin/attribution.
 *
 * It carries only aggregates from AttributionReport: channel and stream
 * names and counts. No lead's name, email, company or message.
 *
 * It is sent synchronously from inside the queued
 * PostWeeklyAttributionSummary job, which owns retries and idempotency.
 */
class WeeklyAttributionSummary extends Notification
{
    /** Streams listed by name; the rest are summed into one line, to stay inside Discord's limit. */
    public const MAX_STREAMS = 10;

    /** Twitch streams listed in the audience section. */
    public const MAX_TWITCH_STREAMS = 7;

    /**
     * @param  list<StreamMetrics>  $twitchStreams  sessions that started in the report's range
     */
    public function __construct(public AttributionReport $report, public array $twitchStreams = []) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebhookChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toWebhook(object $notifiable, string $url): array
    {
        return WebhookChannel::body($url, $this->text());
    }

    public function text(): string
    {
        $report = $this->report;
        $link = route('admin.attribution', [
            'from' => $report->range->from->toDateString(),
            'to' => $report->range->lastDay()->toDateString(),
        ]);
        $lines = ['EDOS weekly attribution, '.$report->range->label()];

        if ($report->isEmpty()) {
            $lines[] = 'No short-link clicks and no enquiries that week.';
        } else {
            array_push($lines, ...$this->attributionLines());
        }

        if ($this->twitchStreams !== []) {
            array_push($lines, '', ...$this->twitchLines());
        }

        // A blank line before the link, except in the two-line quiet week.
        if (count($lines) > 2) {
            $lines[] = '';
        }
        $lines[] = 'Full report: '.$link;

        return implode("\n", $lines);
    }

    /**
     * Totals, top stream, by channel and by stream.
     *
     * @return list<string>
     */
    private function attributionLines(): array
    {
        $report = $this->report;
        $lines = [];

        $lines[] = 'Total: '.self::counts($report->totals());

        $top = collect($report->streams)->first(fn (AttributionRow $row) => $row->channel !== null);
        if ($top !== null) {
            $lines[] = 'Top stream: '.self::name($top).' ('.self::counts($top).')';
        }

        $lines[] = '';
        $lines[] = 'By channel:';
        foreach ($report->channels() as $row) {
            $lines[] = '• '.($row->channel ?? 'no short link').': '.self::counts($row);
        }

        $lines[] = '';
        $lines[] = 'By stream:';
        $streams = $report->streams;
        foreach (array_slice($streams, 0, self::MAX_STREAMS) as $row) {
            $lines[] = '• '.self::name($row).': '.self::counts($row);
        }

        $rest = array_slice($streams, self::MAX_STREAMS);
        if ($rest !== []) {
            $lines[] = '• and '.count($rest).' more: '.self::counts(new AttributionRow(
                null,
                null,
                array_sum(array_map(fn (AttributionRow $row) => $row->clicks, $rest)),
                array_sum(array_map(fn (AttributionRow $row) => $row->leads, $rest)),
            ));
        }

        return $lines;
    }

    /**
     * Audience per Twitch stream, next to that stream's clicks and enquiries.
     *
     * @return list<string>
     */
    private function twitchLines(): array
    {
        $lines = ['Twitch streams (participation ≈ unique chatters ÷ average viewers, an approximation):'];

        foreach (array_slice($this->twitchStreams, 0, self::MAX_TWITCH_STREAMS) as $metrics) {
            $attributed = $this->report->forStream($metrics->stream());
            $lines[] = '• '.$metrics->stream().': '
                .($metrics->averageViewers === null ? 'no viewer samples' : 'avg '.$metrics->averageLabel().' viewers, peak '.$metrics->peakLabel())
                .', '.$metrics->uniqueChatters.' unique '.($metrics->uniqueChatters === 1 ? 'chatter' : 'chatters')
                .', '.$metrics->participationLabel().' participation'
                .', '.self::counts($attributed);
        }

        $more = count($this->twitchStreams) - self::MAX_TWITCH_STREAMS;
        if ($more > 0) {
            $lines[] = '• and '.$more.' more on the full report';
        }

        return $lines;
    }

    /** "twitch / 2026-10-01-stream-123", or "no short link" for direct enquiries. */
    private static function name(AttributionRow $row): string
    {
        return $row->channel === null ? 'no short link' : $row->channel.' / '.($row->stream ?? 'no campaign');
    }

    /** "40 clicks, 2 enquiries, 5.0% conversion". */
    private static function counts(AttributionRow $row): string
    {
        return $row->clicks.' '.($row->clicks === 1 ? 'click' : 'clicks').', '
            .$row->leads.' '.($row->leads === 1 ? 'enquiry' : 'enquiries').', '
            .$row->conversionLabel().' conversion';
    }
}
