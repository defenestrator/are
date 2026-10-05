<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The kill switch was thrown, from /bus, /agent, the kill-switch API or the
 * CLI. By the time listeners run, the switch is committed: the bus and the
 * agent are already stopped. CutToIntermission switches the stream scene.
 */
class KillSwitchThrown implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public ?int $moderatorId, public ?string $reason) {}
}
