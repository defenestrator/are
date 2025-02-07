<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateTwitchEventSubKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'twitch:generate-event-sub-key';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $randomString = Str::random(90);

        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        if (strpos($envContent, 'TWITCH_HELIX_EVENTSUB_SECRET=') !== false) {
            $envContent = preg_replace(
                '/^TWITCH_HELIX_EVENTSUB_SECRET=.*$/m',
                'TWITCH_HELIX_EVENTSUB_SECRET="'.$randomString.'"',
                $envContent
            );
        } else {
            $envContent .= "\nTWITCH_HELIX_EVENTSUB_SECRET=\"{$randomString}\"\n";
        }

        file_put_contents($envPath, $envContent);

        $this->info('TWITCH_HELIX_EVENTSUB_SECRET updated successfully in .env.');
    }
}
