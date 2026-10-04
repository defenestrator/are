<?php

namespace App\YouTube;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The weekly times the show may be live, when the scheduler looks for the
 * live YouTube video (#127). Outside them, discovery spends no quota.
 *
 * Configured as YOUTUBE_SHOW_WINDOWS, comma separated, each "day HH:MM-HH:MM"
 * in YOUTUBE_SHOW_TIMEZONE, for example "sun 17:00-21:00,wed 18:00-20:00".
 * A window whose end is not after its start runs past midnight into the next
 * day ("fri 22:00-01:00"). Entries that do not parse are ignored and logged.
 */
class ShowWindows
{
    private const DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    public static function isOpen(?CarbonInterface $at = null): bool
    {
        $timezone = (string) config('services.youtube.auto.timezone') ?: 'UTC';
        $now = Carbon::instance($at ?? now())->setTimezone($timezone);

        foreach (self::parse() as [$day, $start, $end]) {
            // Check the window that began today and the one that began
            // yesterday, which may run past midnight into today.
            foreach ([0, 1] as $daysAgo) {
                $opened = $now->copy()->subDays($daysAgo)->startOfDay();
                if ($opened->dayOfWeek !== $day) {
                    continue;
                }

                $from = $opened->copy()->addMinutes($start);
                $until = $opened->copy()->addMinutes($end > $start ? $end : $end + 24 * 60);

                if ($now->greaterThanOrEqualTo($from) && $now->lessThan($until)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}> [day of week, start minute, end minute]
     */
    public static function parse(): array
    {
        $windows = [];

        foreach ((array) config('services.youtube.auto.windows', []) as $entry) {
            if (! preg_match('/^\s*(sun|mon|tue|wed|thu|fri|sat)[a-z]*\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/i', (string) $entry, $m)
                || (int) $m[2] > 24 || (int) $m[4] > 24 || (int) $m[3] > 59 || (int) $m[5] > 59) {
                logger()->warning('Ignoring a YOUTUBE_SHOW_WINDOWS entry that does not parse', ['entry' => $entry]);

                continue;
            }

            $windows[] = [self::DAYS[strtolower($m[1])], (int) $m[2] * 60 + (int) $m[3], (int) $m[4] * 60 + (int) $m[5]];
        }

        return $windows;
    }
}
