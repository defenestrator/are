<?php

namespace App\Analytics;

/**
 * Short-link clicks and consented enquiries for one channel (utm_source)
 * and, optionally, one stream (utm_campaign). A null channel means the
 * enquiry arrived without clicking a short link first.
 */
final class AttributionRow
{
    public function __construct(
        public readonly ?string $channel,
        public readonly ?string $stream,
        public readonly int $clicks,
        public readonly int $leads,
    ) {}

    /** Leads ÷ clicks, or null when there were no clicks to divide by. */
    public function conversion(): ?float
    {
        return $this->clicks === 0 ? null : $this->leads / $this->clicks;
    }

    public function conversionLabel(): string
    {
        $conversion = $this->conversion();

        return $conversion === null ? '—' : number_format($conversion * 100, 1).'%';
    }
}
