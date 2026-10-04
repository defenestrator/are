<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EventSub keeps bans and moderators current in real time; this catches anything it missed.
Schedule::command('twitch:sync-moderation')->hourly()->withoutOverlapping();

// Feeds the Horizon metrics dashboard (job and queue wait times, throughput).
Schedule::command('horizon:snapshot')->everyFiveMinutes();
