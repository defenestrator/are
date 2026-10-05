<?php

namespace App\Console\Commands;

use App\Models\MusicPlayerToken;
use Illuminate\Console\Command;

/**
 * Issue, rotate or revoke the token a local player uses to advance the song
 * request queue through POST /music/requests/advance (#136).
 */
class IssueMusicPlayerToken extends Command
{
    protected $signature = 'music:player-token
        {name : A short name for the player, such as obs or shortcut (a-z, 0-9, -)}
        {--rotate : Replace the existing token; the old one stops working}
        {--revoke : Delete the token; the player can no longer advance the queue}';

    protected $description = 'Issue, rotate or revoke a local music player token and print it once';

    public function handle(): int
    {
        $name = strtolower((string) $this->argument('name'));

        if (preg_match(MusicPlayerToken::NAME_PATTERN, $name) !== 1) {
            $this->error('The name must be 1 to 32 characters of a-z, 0-9 and -, starting with a letter or digit.');

            return self::INVALID;
        }

        $existing = MusicPlayerToken::where('name', $name)->first();

        if ($this->option('revoke')) {
            if ($existing === null) {
                $this->error("There is no {$name} player token.");

                return self::FAILURE;
            }

            $existing->delete();
            $this->info("Revoked the {$name} player token.");

            return self::SUCCESS;
        }

        if ($existing !== null && ! $this->option('rotate')) {
            $this->error("The {$name} player already has a token, and it cannot be shown again.");
            $this->line('Run with --rotate to issue a new one. The current token will stop working.');

            return self::FAILURE;
        }

        $token = MusicPlayerToken::issue($name);

        $this->info("New token for the {$name} player. It is shown only this once.");
        $this->newLine();
        $this->line('  '.$token);
        $this->newLine();
        $this->line('Advance the queue when a track starts:');
        $this->line('  curl -fsS -X POST -H "Authorization: Bearer <token>" '.route('music.requests.advance'));
        $this->line('Add -d action=done to only mark the current request played.');
        $this->comment('Treat the token like a password. Keep it out of URLs and shared scripts.');

        return self::SUCCESS;
    }
}
