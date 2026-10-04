<?php

namespace App\Console\Commands;

use App\ControlBus\Game;
use App\Models\BusAdapterToken;
use Illuminate\Console\Command;

class BusToken extends Command
{
    protected $signature = 'bus:token
        {game : A game key from config/bus.php}
        {--rotate : Replace the existing token; adapters using it stop working}';

    protected $description = 'Issue or rotate the token a game adapter uses to poll the Chat Control Bus';

    public function handle(): int
    {
        $game = Game::find((string) $this->argument('game'));

        if ($game === null) {
            $this->error('Unknown game. Choose one of: '.implode(', ', array_keys(Game::all())).'.');

            return self::INVALID;
        }

        if (BusAdapterToken::where('game', $game->key)->exists() && ! $this->option('rotate')) {
            $this->error("{$game->key} already has a token, and it cannot be shown again. Run with --rotate to replace it.");

            return self::FAILURE;
        }

        $token = BusAdapterToken::issue($game->key);

        $this->info("Adapter token for {$game->label}. It is shown only this once.");
        $this->newLine();
        $this->line('  GET '.route('bus.actions', ['game' => $game->key]).'?after=0');
        $this->line('  Authorization: Bearer '.$token);
        $this->newLine();
        $this->comment("Or subscribe to the public Reverb channel bus.{$game->key} (events bus.action, bus.veto, bus.state).");

        return self::SUCCESS;
    }
}
