<?php

namespace App\Analytics;

use App\Models\Lead;
use App\Models\ShortLink;
use App\Models\ShortLinkClick;

/**
 * Answers "which show, on which channel, produced leads?" for a date range.
 *
 * Clicks are dated short-link clicks, grouped by the clicked link's
 * utm_source (channel) and utm_campaign (stream). Leads are consented
 * enquiries created in the range, grouped by the UTM params stored on the
 * lead, which come from the last short link that browser clicked. A lead
 * and its click can fall in different ranges, so conversion can exceed
 * 100% for a range.
 *
 * Only counts leave this class: no lead's name, email, company or message.
 */
final class AttributionReport
{
    /**
     * @param  list<AttributionRow>  $streams
     */
    private function __construct(
        public readonly DateRange $range,
        public readonly array $streams,
        public readonly int $undatedClicks,
    ) {}

    public static function for(DateRange $range): self
    {
        $clicks = ShortLinkClick::query()
            ->join('short_links', 'short_links.id', '=', 'short_link_clicks.short_link_id')
            ->where('short_link_clicks.clicked_at', '>=', $range->from)
            ->where('short_link_clicks.clicked_at', '<', $range->until)
            ->groupBy('short_links.utm_source', 'short_links.utm_campaign')
            ->select('short_links.utm_source', 'short_links.utm_campaign')
            ->selectRaw('count(*) as total')
            ->toBase()
            ->get();

        $leads = Lead::query()
            ->whereNotNull('consented_at')
            ->where('created_at', '>=', $range->from)
            ->where('created_at', '<', $range->until)
            ->groupBy('utm_source', 'utm_campaign')
            ->select('utm_source', 'utm_campaign')
            ->selectRaw('count(*) as total')
            ->toBase()
            ->get();

        /** @var array<string, array{0: ?string, 1: ?string, 2: int, 3: int}> $merged */
        $merged = [];

        foreach ([[$clicks, 2], [$leads, 3]] as [$counts, $slot]) {
            foreach ($counts as $count) {
                $key = json_encode([$count->utm_source, $count->utm_campaign]);
                $merged[$key] ??= [$count->utm_source, $count->utm_campaign, 0, 0];
                $merged[$key][$slot] += (int) $count->total;
            }
        }

        $streams = array_map(fn (array $m) => new AttributionRow($m[0], $m[1], $m[2], $m[3]), array_values($merged));

        return new self($range, self::sorted($streams), self::undatedClicks());
    }

    /**
     * One row per channel, summing its streams.
     *
     * @return list<AttributionRow>
     */
    public function channels(): array
    {
        $channels = [];

        foreach ($this->streams as $row) {
            $key = $row->channel ?? "\0";
            $previous = $channels[$key] ?? new AttributionRow($row->channel, null, 0, 0);
            $channels[$key] = new AttributionRow($row->channel, null, $previous->clicks + $row->clicks, $previous->leads + $row->leads);
        }

        return self::sorted(array_values($channels));
    }

    public function totals(): AttributionRow
    {
        return new AttributionRow(
            null,
            null,
            array_sum(array_map(fn (AttributionRow $row) => $row->clicks, $this->streams)),
            array_sum(array_map(fn (AttributionRow $row) => $row->leads, $this->streams)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->streams === [];
    }

    /**
     * Clicks counted before clicks were dated (short_link_clicks). They are
     * in each link's lifetime counter but in no date range.
     */
    private static function undatedClicks(): int
    {
        return max(0, (int) ShortLink::sum('clicks') - ShortLinkClick::count());
    }

    /**
     * Most leads first, then most clicks, then by name; leads with no short
     * link go last.
     *
     * @param  list<AttributionRow>  $rows
     * @return list<AttributionRow>
     */
    private static function sorted(array $rows): array
    {
        usort($rows, fn (AttributionRow $a, AttributionRow $b) => [$a->channel === null, -$a->leads, -$a->clicks, $a->channel, $a->stream]
            <=> [$b->channel === null, -$b->leads, -$b->clicks, $b->channel, $b->stream]);

        return $rows;
    }
}
