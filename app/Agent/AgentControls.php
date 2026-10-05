<?php

namespace App\Agent;

use App\Models\BusControl;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stop and start the VTuber agent alone, leaving the Chat Control Bus
 * running. For everything at once, use the kill switch (ControlBus::kill).
 */
class AgentControls
{
    public function stop(User $moderator): void
    {
        $this->set($moderator, true);
    }

    public function start(User $moderator): void
    {
        $this->set($moderator, false);
    }

    private function set(User $moderator, bool $stopped): void
    {
        Gate::forUser($moderator)->authorize('moderate');

        BusControl::for(AgentGate::SCOPE);

        // FOR UPDATE: waits for any agent effect in flight (they hold this
        // row FOR SHARE in AgentGate::whileAllowed), then stops the next.
        DB::transaction(function () use ($moderator, $stopped) {
            BusControl::whereKey(AgentGate::SCOPE)->lockForUpdate()->firstOrFail()->update([
                'paused_at' => $stopped ? now() : null,
                'paused_by_id' => $stopped ? $moderator->id : null,
            ]);

            ModerationAction::record($moderator, $stopped ? 'agent.stopped' : 'agent.started');
        });
    }
}
