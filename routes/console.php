<?php

use App\Jobs\PostWeeklyAttributionSummary;
use App\Jobs\SampleTwitchViewers;
use App\Models\ChatCommandRun;
use App\Models\LinkCode;
use App\Models\StreamSession;
use App\Models\YouTubeLiveChat;
use App\Readiness\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EventSub keeps bans and moderators current in real time; this catches anything it missed.
Schedule::command('twitch:sync-moderation')->hourly()->withoutOverlapping();

// Feeds the Horizon metrics dashboard (job and queue wait times, throughput).
// Horizon only runs once the queue is on Redis; until then the snapshot would
// just fail to reach Redis every five minutes.
Schedule::command('horizon:snapshot')->everyFiveMinutes()
    ->when(fn () => config('queue.default') === 'redis');

// Chat Control Bus: each window has a delayed ResolveBusWindow job; this
// catches any window it missed (a lost job, or a worker that was down).
Schedule::command('bus:resolve')->everyTenSeconds()->withoutOverlapping();

// Chat commands claim each message id once; a week of claims is ample.
Schedule::command('model:prune', ['--model' => [ChatCommandRun::class, LinkCode::class]])->daily();

// Last week's attribution to Slack or Discord (#12): Mondays 09:00 app time
// by default. The job claims its ISO week, so it never double-posts.
Schedule::job(new PostWeeklyAttributionSummary)
    ->weeklyOn((int) config('are.weekly_summary.day'), (string) config('are.weekly_summary.time'))
    ->timezone((string) config('app.timezone'))
    ->when(fn () => PostWeeklyAttributionSummary::webhookUrl() !== null);

Artisan::command('attribution:weekly-summary {--week= : A Y-m-d date in the week to post; defaults to last week}', function () {
    if (PostWeeklyAttributionSummary::webhookUrl() === null) {
        $this->error('No webhook: set ARE_WEEKLY_SUMMARY_WEBHOOK_URL or ARE_LEADS_WEBHOOK_URL.');

        return 1;
    }

    $week = $this->option('week') ?: null;
    if ($week !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
        $this->error('--week must be a date like 2026-09-28.');

        return 1;
    }

    $job = new PostWeeklyAttributionSummary($week);
    dispatch($job);
    $this->info("Queued the summary for {$job->isoWeek()}. A week that was already posted is not posted again.");

    return 0;
})->purpose('Queue the weekly attribution summary for Slack or Discord');

// Proves the scheduler runs, for the readiness page (#135).
Schedule::call(fn () => SchedulerHeartbeat::beat())
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Restarts YouTube live chat polling whose job chain was lost (#24).
Schedule::call(fn () => YouTubeLiveChat::resumeStalled())
    ->everyMinute()
    ->name('youtube-chat-watchdog')
    ->withoutOverlapping();

// Concurrent viewers of live Twitch streams (#12), every three minutes while
// any stream session is open. stream.online takes the first sample; this
// tick is the watchdog, so sampling cannot silently stop mid-stream, and it
// stops by itself once stream.offline closes the session.
Schedule::job(new SampleTwitchViewers)
    ->everyThreeMinutes()
    ->when(fn () => StreamSession::live()->exists());
