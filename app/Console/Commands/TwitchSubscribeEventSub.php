<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Twitch;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

class TwitchSubscribeEventSub extends Command
{
    protected $signature = 'twitch:eventsub-subscribe';

    protected $description = 'Creates the EventSub webhook subscriptions for moderation, chat, redemptions, subs, raids, follows and stream state.';

    public function handle(): int
    {
        if (! config('services.twitch.eventsub_secret')) {
            $this->error('TWITCH_HELIX_EVENTSUB_SECRET is not set. Run php artisan twitch:generate-event-sub-key first.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach (User::getBroadcasterIDs() as $broadcasterId) {
            foreach (Twitch::EVENTSUB_TYPES as $type) {
                try {
                    Twitch::subscribeEventSub($broadcasterId, $type);
                    $this->line("{$broadcasterId}: {$type}");
                } catch (RequestException $e) {
                    // Usually a missing scope: the broadcaster must reconnect at /twitch/broadcaster/connect.
                    $failed++;
                    $this->error("{$broadcasterId}: {$type} failed ({$e->response->status()}): ".$e->response->json('message', ''));
                }
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} subscription(s) failed. A 403 usually means the broadcaster must reconnect their channel to grant new scopes.");

            return self::FAILURE;
        }

        $this->info('EventSub subscriptions are in place.');

        return self::SUCCESS;
    }
}
