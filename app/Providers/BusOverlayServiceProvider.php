<?php

namespace App\Providers;

use App\Jobs\BroadcastBusTally;
use App\Models\BusApproval;
use App\Models\BusBallot;
use App\Models\BusControl;
use App\Models\BusPublication;
use App\Models\BusWindow;
use App\Models\ModerationAction;
use Illuminate\Support\ServiceProvider;

/**
 * Keeps the on-stream bus overlay live (#138) without touching ControlBus:
 * every change to what the overlay shows asks for a coalesced bus.tally.
 *
 * Model events cover ballots, windows, approvals, publications and control
 * rows. ControlBus also does some mass updates, which fire no model events,
 * but each happens inside a moderator control that writes a `bus.*`
 * moderation record (an option veto, a mode or game change, the kill switch).
 * Watching those records covers them.
 */
class BusOverlayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $touchGame = fn ($model) => $model->game !== null && BroadcastBusTally::touch((string) $model->game);

        foreach ([BusBallot::class, BusWindow::class, BusApproval::class, BusPublication::class] as $model) {
            $model::saved($touchGame);
        }

        BusControl::saved(function (BusControl $control) {
            // The global row holds the kill switch and the running game, which
            // every game's overlay shows; a game row holds its pause and mode.
            $control->scope === BusControl::GLOBAL
                ? BroadcastBusTally::touchAll()
                : BroadcastBusTally::touch($control->scope);
        });

        ModerationAction::created(function (ModerationAction $action) {
            if (! str_starts_with((string) $action->action, 'bus.')) {
                return;
            }

            $details = $action->getAttribute('details');
            $game = is_array($details) ? ($details['game'] ?? null) : null;
            is_string($game) ? BroadcastBusTally::touch($game) : BroadcastBusTally::touchAll();
        });
    }
}
