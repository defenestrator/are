<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One YouTube channel's audience for a date range (#12), from the daily rows
 * FetchYouTubeAnalytics stores.
 *
 * - Views are YouTube's displayed views; engaged views count only plays past
 *   the first frame, so they sit next to views, not instead of them.
 * - Average view duration is total watch time ÷ views across the range.
 * - Views from subscribers are views by viewers subscribed at the time. This
 *   is not "returning viewers": the YouTube Analytics API has no such figure.
 * - YouTube reports with a lag. reportedThrough is the last day YouTube had
 *   reported when the data was fetched. If it is before the range's last day,
 *   the figures cover only part of the range, and isPartial() says so.
 */
final class YouTubeChannelReport
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $title,
        public readonly int $views,
        public readonly int $engagedViews,
        public readonly int $minutesWatched,
        public readonly int $subscriberViews,
        public readonly int $liveStreamViews,
        public readonly ?CarbonImmutable $reportedThrough,
        public readonly DateRange $range,
    ) {}

    /**
     * One report per channel, in the order given.
     *
     * @param  array<string, string>  $channels  channel id => title
     * @return list<self>
     */
    public static function for(DateRange $range, array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        $totals = DB::table('youtube_channel_days')
            ->whereIn('channel_id', array_keys($channels))
            ->where('day', '>=', $range->from->toDateString())
            ->where('day', '<', $range->until->toDateString())
            ->groupBy('channel_id')
            ->select('channel_id')
            ->selectRaw('sum(views) as views')
            ->selectRaw('sum(engaged_views) as engaged_views')
            ->selectRaw('sum(estimated_minutes_watched) as minutes')
            ->selectRaw("sum(case when subscribed_status = 'SUBSCRIBED' then views else 0 end) as subscriber_views")
            ->selectRaw("sum(case when content_type = 'LIVE_STREAM' then views else 0 end) as live_views")
            ->get()
            ->keyBy('channel_id');

        $through = DB::table('youtube_analytics_syncs')->whereIn('channel_id', array_keys($channels))->pluck('reported_through', 'channel_id');

        $reports = [];
        foreach ($channels as $channelId => $title) {
            $row = $totals->get($channelId);
            $day = $through->get($channelId);

            $reports[] = new self(
                (string) $channelId,
                $title,
                (int) ($row->views ?? 0),
                (int) ($row->engaged_views ?? 0),
                (int) ($row->minutes ?? 0),
                (int) ($row->subscriber_views ?? 0),
                (int) ($row->live_views ?? 0),
                $day === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $day, 0, 10)),
                $range,
            );
        }

        return $reports;
    }

    /** Average view duration in seconds, or null with no views. */
    public function averageViewDuration(): ?int
    {
        return $this->views === 0 ? null : (int) round($this->minutesWatched * 60 / $this->views);
    }

    /** "4:05", or "—". */
    public function averageViewDurationLabel(): string
    {
        $seconds = $this->averageViewDuration();

        return $seconds === null ? '—' : intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /** "38%" of views from subscribers, or "—". */
    public function subscriberShareLabel(): string
    {
        return $this->views === 0 ? '—' : number_format($this->subscriberViews / $this->views * 100, 0).'%';
    }

    /** Whether YouTube has not yet reported every day of the range (or nothing at all). */
    public function isPartial(): bool
    {
        return $this->reportedThrough === null || $this->reportedThrough->lt($this->range->lastDay()->startOfDay());
    }

    /** The last day these figures include: the range's last day, or YouTube's last reported day if earlier. */
    public function coversThrough(): ?CarbonImmutable
    {
        if ($this->reportedThrough === null || $this->reportedThrough->lt($this->range->from)) {
            return null;
        }

        return $this->isPartial() ? $this->reportedThrough : $this->range->lastDay();
    }

    /** "Mon 28 Sep to Sat 3 Oct (YouTube has reported through Sat 3 Oct)", or the full range. */
    public function coverageLabel(): string
    {
        $through = $this->coversThrough();

        if ($through === null) {
            return 'no YouTube data for this range yet';
        }

        $label = $this->range->from->format('D j M').' to '.$through->format('D j M');

        return $this->isPartial() ? $label.' (YouTube has reported through '.$through->format('D j M').')' : $label;
    }
}
