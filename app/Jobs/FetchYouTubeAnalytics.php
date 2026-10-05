<?php

namespace App\Jobs;

use App\YouTube\AnalyticsTokens;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pulls daily YouTube Analytics for every connected channel (#12) into
 * youtube_channel_days, one reports.query call per channel.
 *
 * The query is the "user activity by subscribed status" channel report:
 * dimensions day, creatorContentType and subscribedStatus; metrics views,
 * engagedViews, estimatedMinutesWatched and averageViewDuration.
 *
 * Every run re-fetches the last WINDOW_DAYS days and upserts them, because
 * YouTube fills in and revises recent days. The response stops at "the last
 * day for which all metrics in the query are available", so the latest day
 * returned is recorded as the channel's reported_through date.
 *
 * Each request costs one unit of the YouTube Analytics API's own quota, not
 * the Data API's 10,000 units. A channel that fails is logged, without its
 * token, and the job fails after trying the rest, so the queue retries;
 * upserts make the retry safe.
 *
 * @see https://developers.google.com/youtube/analytics/reference/reports/query
 * @see https://developers.google.com/youtube/analytics/channel_reports
 */
class FetchYouTubeAnalytics implements ShouldQueue
{
    use Queueable;

    public const URL = 'https://youtubeanalytics.googleapis.com/v2/reports';

    public const WINDOW_DAYS = 14;

    public const METRICS = ['views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration'];

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [300, 1800];

    public function handle(AnalyticsTokens $tokens): void
    {
        $failed = [];

        foreach (array_keys($tokens->channels()) as $channelId) {
            try {
                $this->fetch($tokens, (string) $channelId);
            } catch (Throwable $e) {
                $failed[] = $channelId;
                // The message never contains a token: Google's error code at most.
                Log::warning('Could not fetch YouTube Analytics for a channel.', ['channel_id' => $channelId, 'error' => $e->getMessage()]);
            }
        }

        if ($failed !== []) {
            throw new RuntimeException('YouTube Analytics fetch failed for '.count($failed).' channel(s); retrying.');
        }
    }

    private function fetch(AnalyticsTokens $tokens, string $channelId): void
    {
        $today = CarbonImmutable::today();

        try {
            $response = Http::withToken($tokens->accessTokenFor($channelId))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get(self::URL, [
                    'ids' => 'channel==MINE',
                    'startDate' => $today->subDays(self::WINDOW_DAYS)->toDateString(),
                    'endDate' => $today->toDateString(),
                    'dimensions' => 'day,creatorContentType,subscribedStatus',
                    'metrics' => implode(',', self::METRICS),
                    'sort' => 'day',
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('connection failed');
        }

        if ($response->failed()) {
            throw new RuntimeException('HTTP '.$response->status().' '.(string) $response->json('error.errors.0.reason', $response->json('error.status', '')));
        }

        $columns = array_map(fn ($header) => (string) ($header['name'] ?? ''), (array) $response->json('columnHeaders', []));
        $rows = [];
        $reportedThrough = null;

        foreach ((array) $response->json('rows', []) as $values) {
            if (! is_array($values) || count($values) !== count($columns)) {
                continue;
            }

            $row = array_combine($columns, $values);
            $day = (string) ($row['day'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }

            $reportedThrough = max($reportedThrough ?? $day, $day);
            $rows[] = [
                'channel_id' => $channelId,
                'day' => $day,
                'content_type' => (string) ($row['creatorContentType'] ?? 'UNSPECIFIED'),
                'subscribed_status' => (string) ($row['subscribedStatus'] ?? 'UNSUBSCRIBED'),
                'views' => max(0, (int) ($row['views'] ?? 0)),
                'engaged_views' => max(0, (int) ($row['engagedViews'] ?? 0)),
                'estimated_minutes_watched' => max(0, (int) ($row['estimatedMinutesWatched'] ?? 0)),
                'average_view_duration' => max(0, (int) ($row['averageViewDuration'] ?? 0)),
            ];
        }

        DB::transaction(function () use ($channelId, $rows, $reportedThrough) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('youtube_channel_days')->upsert(
                    $chunk,
                    ['channel_id', 'day', 'content_type', 'subscribed_status'],
                    ['views', 'engaged_views', 'estimated_minutes_watched', 'average_view_duration'],
                );
            }

            // Some drivers return a date with a time part; compare Y-m-d only.
            $existing = DB::table('youtube_analytics_syncs')->where('channel_id', $channelId)->value('reported_through');
            $existing = $existing === null ? null : substr((string) $existing, 0, 10);
            $through = $reportedThrough === null ? $existing : max($existing ?? $reportedThrough, $reportedThrough);

            DB::table('youtube_analytics_syncs')->upsert(
                [['channel_id' => $channelId, 'reported_through' => $through, 'synced_at' => now()]],
                ['channel_id'],
                ['reported_through', 'synced_at'],
            );
        });
    }
}
