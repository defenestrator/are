<?php

namespace App\Console\Commands;

use App\Models\BroadcasterToken;
use App\Models\User;
use App\Twitch;
use Illuminate\Console\Command;

class TwitchSyncModeration extends Command
{
    protected $signature = 'twitch:sync-moderation';

    protected $description = 'Refreshes the local ban and moderator lists from Twitch for every connected broadcaster.';

    public function handle(): int
    {
        $connected = BroadcasterToken::whereIn('broadcaster_id', User::getBroadcasterIDs())->pluck('broadcaster_id');

        if ($connected->isEmpty()) {
            $this->warn('No broadcaster has connected their channel yet (visit /twitch/broadcaster/connect).');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($connected as $broadcasterId) {
            try {
                $counts = Twitch::syncModeration($broadcasterId);
                $this->info("{$broadcasterId}: {$counts['bans']} bans, {$counts['moderators']} moderators");
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$broadcasterId}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
