<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Twitch;
use Illuminate\Console\Command;

class TwitchSubscribeEventSub extends Command
{
    protected $signature = 'twitch:eventsub-subscribe';

    protected $description = 'Creates the EventSub webhook subscriptions that keep bans and moderators current.';

    public function handle(): int
    {
        if (! config('services.twitch.eventsub_secret')) {
            $this->error('TWITCH_HELIX_EVENTSUB_SECRET is not set. Run php artisan twitch:generate-event-sub-key first.');

            return self::FAILURE;
        }

        foreach (User::getBroadcasterIDs() as $broadcasterId) {
            foreach (Twitch::EVENTSUB_TYPES as $type) {
                Twitch::subscribeEventSub($broadcasterId, $type);
                $this->line("{$broadcasterId}: {$type}");
            }
        }

        $this->info('EventSub subscriptions are in place.');

        return self::SUCCESS;
    }
}
