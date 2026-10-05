<?php

namespace App\Listeners;

use App\Agent\SceneSwitcher;
use App\Events\KillSwitchThrown;
use App\Models\ModerationAction;
use App\Models\User;

/**
 * Cuts the stream to the intermission scene when the kill switch is thrown
 * (#10). Runs in the same request, right after the switch commits, so the
 * scene changes at once rather than when a worker gets to it; the OBS call
 * has a short timeout, and a failure is recorded, never thrown.
 */
class CutToIntermission
{
    public function __construct(private SceneSwitcher $scenes) {}

    public function handle(KillSwitchThrown $event): void
    {
        $switched = $this->scenes->cutToIntermission();

        ModerationAction::record(
            $event->moderatorId === null ? null : User::find($event->moderatorId),
            'bus.intermission',
            null,
            ['scene' => config('agent.obs.intermission_scene'), 'driver' => config('agent.obs.driver'), 'switched' => $switched],
        );
    }
}
