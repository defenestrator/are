<?php

namespace App\Console\Commands;

use App\ControlBus\ControlBus;
use Illuminate\Console\Command;

/**
 * The operator's kill switch, for when nobody can reach /bus.
 */
class BusKill extends Command
{
    protected $signature = 'bus:kill
        {--off : Reset the kill switch instead}';

    protected $description = 'Stop the Chat Control Bus for every game (or, with --off, start it again)';

    public function handle(ControlBus $bus): int
    {
        if ($this->option('off')) {
            $bus->restore(null, 'bus:kill --off');
            $this->info('Kill switch reset. The bus publishes again.');

            return self::SUCCESS;
        }

        $bus->kill(null, 'bus:kill', 'bus:kill');
        $this->warn('Kill switch on. Nothing is published for any game until `bus:kill --off` or a broadcaster resets it on /bus.');

        return self::SUCCESS;
    }
}
