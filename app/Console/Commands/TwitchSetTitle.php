<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TwitchSetTitle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage example: php artisan twitch:title "New Title"
     */
    protected $signature = 'twitch:title {title : The new stream title}';

    /**
     * The console command description.
     */
    protected $description = 'Updates the Twitch stream title using the Twitch CLI tool.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get the title argument
        $newTitle = $this->argument('title');

        // Example shell command for changing the title using the Twitch CLI
        // Adjust the path/command per your environment
        // e.g., "twitch set title 'My new stream title'"
        $command = sprintf('twitch set title "%s"', escapeshellcmd($newTitle));

        // Execute the command
        $output = shell_exec($command);

        // Display the result
        $this->info($output ?: "Stream title updated to: \"{$newTitle}\"");
    }
}
