<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Twitch;
use Illuminate\Console\Command;

class TwitchSetTitle extends Command
{
    /**
     * Usage: php artisan twitch:title "New Title" [--broadcaster=<twitch id>]
     */
    protected $signature = 'twitch:title
        {title : The new stream title}
        {--broadcaster= : Twitch user id of the channel; defaults to TWITCH_CHANNEL_ID}';

    protected $description = 'Updates the Twitch stream title through the Helix API.';

    public function handle(): int
    {
        $title = trim($this->argument('title'));
        $broadcasterId = $this->option('broadcaster') ?: User::getBroadcasterID();

        if ($title === '' || mb_strlen($title) > 140) {
            $this->error('The title must be between 1 and 140 characters.');

            return self::FAILURE;
        }

        if (! in_array($broadcasterId, User::getBroadcasterIDs(), true)) {
            $this->error("{$broadcasterId} is not a broadcaster this app serves.");

            return self::FAILURE;
        }

        Twitch::setTitle($broadcasterId, $title);
        $this->info("Stream title updated to: \"{$title}\"");

        return self::SUCCESS;
    }
}
