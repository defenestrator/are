<?php

namespace App\Console\Commands;

use App\Models\Agent;
use Illuminate\Console\Command;

class AgentToken extends Command
{
    protected $signature = 'agent:token
        {name : The agent, e.g. "orkestera-vtuber"; created if new}
        {--rotate : Revoke the agent\'s existing tokens first}
        {--ability=* : Limit the token to these abilities (default: all of them)}';

    protected $description = 'Issue an API token for a VTuber agent and print it once';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name)) {
            $this->error('Use 1 to 64 lowercase letters, digits, "-" or "_".');

            return self::INVALID;
        }

        $abilities = (array) $this->option('ability') ?: Agent::ABILITIES;
        $unknown = array_diff($abilities, Agent::ABILITIES);
        if ($unknown !== []) {
            $this->error('Unknown abilities: '.implode(', ', $unknown).'. Choose from: '.implode(', ', Agent::ABILITIES).'.');

            return self::INVALID;
        }

        $agent = Agent::named($name);

        if ($agent->tokens()->exists() && ! $this->option('rotate')) {
            $this->error("{$name} already has a token, and it cannot be shown again. Run with --rotate to revoke it and issue a new one.");

            return self::FAILURE;
        }

        $agent->tokens()->delete();
        $token = $agent->createToken('agent', array_values($abilities))->plainTextToken;

        $this->info("Token for {$name} (abilities: ".implode(', ', $abilities).'). It is shown only this once.');
        $this->newLine();
        $this->line('  Authorization: Bearer '.$token);
        $this->line('  Base URL: '.url('/api/agent'));
        $this->newLine();
        $this->comment('Every request is refused while the kill switch is on or a moderator has stopped the agent on /agent.');

        return self::SUCCESS;
    }
}
