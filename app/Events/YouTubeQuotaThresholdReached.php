<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Today's YouTube quota use in one bucket has crossed the alert ratio.
 * Fired once per bucket per Pacific Time day.
 */
class YouTubeQuotaThresholdReached
{
    use Dispatchable;

    /**
     * @param  string  $bucket  App\YouTube\Quota::UNITS or ::SEARCH
     * @param  string  $day  The Pacific Time day, Y-m-d
     */
    public function __construct(
        public string $bucket,
        public int $used,
        public int $limit,
        public string $day,
    ) {}
}
