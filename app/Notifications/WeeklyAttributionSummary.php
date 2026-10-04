<?php

namespace App\Notifications;

use App\Analytics\AttributionReport;
use App\Analytics\AttributionRow;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Notifications\Notification;

/**
 * Last week's attribution as one Slack or Discord message (#12): totals,
 * the top stream, clicks, enquiries and conversion by channel and by
 * stream, and a link to /admin/attribution.
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

    public function __construct(public AttributionReport $report) {}

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
            $lines[] = 'Full report: '.$link;

            return implode("\n", $lines);
        }

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

        $lines[] = '';
        $lines[] = 'Full report: '.$link;

        return implode("\n", $lines);
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
