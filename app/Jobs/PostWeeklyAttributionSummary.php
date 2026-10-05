<?php

namespace App\Jobs;

use App\Analytics\AttributionReport;
use App\Analytics\DateRange;
use App\Analytics\StreamMetrics;
use App\Analytics\YouTubeChannelReport;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\WeeklyAttributionSummary;
use App\YouTube\AnalyticsTokens;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Posts one ISO week's attribution summary to the Slack or Discord webhook
 * (#12). Scheduled for Monday morning in routes/console.php, it reports the
 * week that just ended.
 *
 * - It does nothing when no webhook is configured.
 * - It posts each ISO week at most once: the week is claimed in
 *   attribution_summaries before posting, so a re-run, a second scheduler
 *   or a duplicate dispatch cannot double-post. A failed post releases the
 *   claim and throws, so the queue retries it.
 * - It sends aggregates only (see WeeklyAttributionSummary).
 */
class PostWeeklyAttributionSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /** The Monday (Y-m-d, app timezone) that starts the reported week. */
    public string $weekStart;

    /**
     * @param  string|null  $weekStart  a Y-m-d date in the week to report; defaults to last week
     */
    public function __construct(?string $weekStart = null)
    {
        $week = $weekStart === null
            ? CarbonImmutable::now()->subWeek()
            : CarbonImmutable::createFromFormat('!Y-m-d', $weekStart);

        $this->weekStart = $week->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public static function webhookUrl(): ?string
    {
        $url = config('are.weekly_summary.webhook_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** e.g. "2026-W40": the ISO week this job reports. */
    public function isoWeek(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart)->format('o-\WW');
    }

    public function handle(): void
    {
        $url = static::webhookUrl();

        if ($url === null || ! $this->claim()) {
            return;
        }

        $range = DateRange::days($this->weekStart, CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart)->addDays(6)->toDateString());

        try {
            (new AnonymousNotifiable)
                ->route(WebhookChannel::class, $url)
                ->notifyNow(new WeeklyAttributionSummary(
                    AttributionReport::for($range),
                    StreamMetrics::forRange($range),
                    YouTubeChannelReport::for($range, app(AnalyticsTokens::class)->channels()),
                ));
        } catch (Throwable $e) {
            DB::table('attribution_summaries')->where('iso_week', $this->isoWeek())->whereNull('posted_at')->delete();

            throw $e;
        }

        DB::table('attribution_summaries')->where('iso_week', $this->isoWeek())->update(['posted_at' => now(), 'updated_at' => now()]);
    }

    /** Atomically claim this ISO week. True only for the first claim. */
    private function claim(): bool
    {
        return DB::table('attribution_summaries')->insertOrIgnore([
            'iso_week' => $this->isoWeek(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
