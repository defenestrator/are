<?php

namespace App\Console\Commands;

use App\ControlBus\ControlBus;
use Illuminate\Console\Command;

class BusResolve extends Command
{
    protected $signature = 'bus:resolve';

    protected $description = 'Close every Chat Control Bus vote window that is due and publish its winner';

    public function handle(ControlBus $bus): int
    {
        $closed = $bus->resolveDue();

        if ($closed > 0) {
            $this->info("Closed {$closed} window(s).");
        }

        return self::SUCCESS;
    }
}
