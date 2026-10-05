<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

class AgentKillToken extends Command
{
    protected $signature = 'agent:kill-token
        {user : The moderator\'s user id}
        {--rotate : Revoke their existing kill-switch tokens first}';

    protected $description = 'Issue a moderator a token for POST /api/kill-switch (a Stream Deck button)';

    public function handle(): int
    {
        $user = User::find((int) $this->argument('user'));

        if ($user === null || Gate::forUser($user)->denies('moderate')) {
            $this->error('That user is not a moderator.');

            return self::INVALID;
        }

        $existing = $user->tokens()->where('name', 'kill-switch');
        if ($existing->exists() && ! $this->option('rotate')) {
            $this->error("{$user->name} already has a kill-switch token. Run with --rotate to replace it.");

            return self::FAILURE;
        }

        $existing->delete();
        $token = $user->createToken('kill-switch', ['kill-switch'])->plainTextToken;

        $this->info("Kill-switch token for {$user->name}. It is shown only this once.");
        $this->newLine();
        $this->line('  POST '.route('kill-switch'));
        $this->line('  Authorization: Bearer '.$token);
        $this->newLine();
        $this->comment('It throws the kill switch (bus, agent and intermission scene). It cannot reset it.');

        return self::SUCCESS;
    }
}
