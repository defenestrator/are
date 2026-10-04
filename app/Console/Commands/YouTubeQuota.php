<?php

namespace App\Console\Commands;

use App\Models\YouTubeLiveChat;
use App\YouTube\Quota;
use Illuminate\Console\Command;

class YouTubeQuota extends Command
{
    protected $signature = 'youtube:quota';

    protected $description = 'Show today\'s YouTube API quota use (Pacific Time day) and where the live chat polling is heading.';

    public function handle(): int
    {
        $day = Quota::day();
        $units = Quota::used(Quota::UNITS);
        $unitLimit = Quota::limit(Quota::UNITS);

        // Each polling chat costs 1 unit per poll, at its current interval.
        $perHour = (int) YouTubeLiveChat::polling()->get()
            ->sum(fn (YouTubeLiveChat $chat) => 3_600_000 / max(1, $chat->poll_interval_ms));

        $this->table(['Bucket', 'Used', 'Limit', 'Alert at', 'Failed calls'], [
            ['units', $units, $unitLimit, Quota::alertThreshold(Quota::UNITS), Quota::failedCalls(Quota::UNITS)],
            ['search.list', Quota::used(Quota::SEARCH), Quota::limit(Quota::SEARCH), Quota::alertThreshold(Quota::SEARCH), Quota::failedCalls(Quota::SEARCH)],
        ]);

        $this->line("Day (PT): {$day}. Resets at ".Quota::resetsAt()->toDateTimeString().' '.config('app.timezone').'.');
        $this->line("Live chat polling now spends about {$perHour} units an hour.");

        if ($perHour > 0) {
            $hoursLeft = max(0, $unitLimit - $units) / $perHour;
            $this->line(sprintf('At that rate the units run out in %.1f hours.', $hoursLeft));
        }

        return self::SUCCESS;
    }
}
