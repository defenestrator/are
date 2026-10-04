<?php

namespace App\YouTube;

use App\Events\YouTubeQuotaThresholdReached;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Counts YouTube Data API quota as ARE spends it, per Google Cloud project and
 * per Pacific Time day, because that is how Google meters and resets it.
 *
 * Every request is charged, failed ones included ("All API requests,
 * including invalid requests, incur a quota cost of at least one point").
 * search.list draws on its own 100-call bucket. Crossing the alert ratio
 * (80% by default) of a bucket fires YouTubeQuotaThresholdReached, once per
 * bucket per day.
 *
 * @see https://developers.google.com/youtube/v3/determine_quota_cost
 */
class Quota
{
    public const UNITS = 'units';

    public const SEARCH = 'search';

    /**
     * Cost per call, in [bucket, cost]. Re-check against Google's calculator
     * before trusting it: the quota model changed in 2026 (spike #23).
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const COSTS = [
        'videos.list' => [self::UNITS, 1],
        'liveChatMessages.list' => [self::UNITS, 1],
        'liveBroadcasts.list' => [self::UNITS, 1],
        'channels.list' => [self::UNITS, 1],
        'liveChatMessages.insert' => [self::UNITS, 50],
        'search.list' => [self::SEARCH, 1],
    ];

    /** Google resets quota at midnight in this zone. */
    public const TIMEZONE = 'America/Los_Angeles';

    /**
     * The Pacific Time day a moment falls in, as Y-m-d.
     */
    public static function day(?Carbon $at = null): string
    {
        return ($at ?? now())->copy()->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * When the current quota day ends (midnight PT), in the app's timezone.
     */
    public static function resetsAt(): Carbon
    {
        return now()->setTimezone(self::TIMEZONE)->addDay()->startOfDay()->setTimezone(config('app.timezone'));
    }

    /**
     * Record one call to $method. Returns the bucket's new total for today.
     */
    public static function charge(string $method, bool $failed = false): int
    {
        if (! isset(self::COSTS[$method])) {
            throw new InvalidArgumentException("No YouTube quota cost is known for {$method}; add it to Quota::COSTS.");
        }

        [$bucket, $cost] = self::COSTS[$method];
        $day = self::day();

        $used = DB::transaction(function () use ($bucket, $cost, $day, $failed) {
            $now = now();
            DB::table('youtube_quota_usage')->insertOrIgnore([
                'day' => $day, 'bucket' => $bucket, 'used' => 0, 'calls' => 0, 'failed_calls' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $row = DB::table('youtube_quota_usage')->where('day', $day)->where('bucket', $bucket);
            (clone $row)->update([
                'used' => DB::raw('used + '.(int) $cost),
                'calls' => DB::raw('calls + 1'),
                'failed_calls' => DB::raw('failed_calls + '.($failed ? 1 : 0)),
                'updated_at' => $now,
            ]);

            return (int) (clone $row)->value('used');
        });

        $threshold = self::alertThreshold($bucket);
        if ($used >= $threshold && $used - $cost < $threshold) {
            YouTubeQuotaThresholdReached::dispatch($bucket, $used, self::limit($bucket), $day);
        }

        return $used;
    }

    public static function used(string $bucket, ?string $day = null): int
    {
        return (int) DB::table('youtube_quota_usage')->where('day', $day ?? self::day())->where('bucket', $bucket)->value('used');
    }

    public static function failedCalls(string $bucket, ?string $day = null): int
    {
        return (int) DB::table('youtube_quota_usage')->where('day', $day ?? self::day())->where('bucket', $bucket)->value('failed_calls');
    }

    public static function limit(string $bucket): int
    {
        return $bucket === self::SEARCH
            ? (int) config('services.youtube.quota.daily_search_calls')
            : (int) config('services.youtube.quota.daily_units');
    }

    public static function alertThreshold(string $bucket): int
    {
        return (int) ceil(self::limit($bucket) * (float) config('services.youtube.quota.alert_ratio'));
    }
}
