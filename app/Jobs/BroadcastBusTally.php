<?php

namespace App\Jobs;

use App\ControlBus\BusOverlay;
use App\ControlBus\Game;
use App\Events\BusTallyChanged;
use App\Events\Concerns\BroadcastsWhenEnabled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sends one bus.tally for a game, however many changes asked for it (#138).
 *
 * A change calls touch(). The first in a burst takes a short-lived pending
 * flag and queues this job, delayed by DELAY_SECONDS; the rest find the flag
 * and do nothing. The job clears the flag *before* it reads the snapshot, so
 * a ballot counted while it sends queues the next tally rather than being
 * lost. Laravel's unique broadcasts hold their lock until after the send,
 * which would lose exactly that ballot, hence this instead.
 *
 * If the job never runs, the flag expires after FLAG_SECONDS and the next
 * change queues a fresh one. With broadcasting off (log or null driver)
 * nothing is queued at all, and the overlay polls.
 */
class BroadcastBusTally implements ShouldQueue
{
    use BroadcastsWhenEnabled, Queueable;

    public const DELAY_SECONDS = 1;

    public const FLAG_SECONDS = 15;

    public function __construct(public string $game)
    {
        $this->onQueue('broadcasts');
    }

    public static function pendingKey(string $game): string
    {
        return 'bus.tally.pending.'.$game;
    }

    /**
     * Ask for a tally broadcast for $game, after the current transaction
     * commits (or now, if there is none).
     */
    public static function touch(string $game): void
    {
        if (! (new self($game))->broadcastWhen() || Game::find($game) === null) {
            return;
        }

        DB::afterCommit(function () use ($game) {
            if (Cache::add(self::pendingKey($game), true, self::FLAG_SECONDS)) {
                self::dispatch($game)->delay(now()->addSeconds(self::DELAY_SECONDS));
            }
        });
    }

    /**
     * Every configured game, for changes that are not about one game (the
     * kill switch, or the running game changing).
     */
    public static function touchAll(): void
    {
        foreach (array_keys(Game::all()) as $game) {
            self::touch($game);
        }
    }

    public function handle(): void
    {
        Cache::forget(self::pendingKey($this->game));

        $game = Game::find($this->game);
        if ($game === null || ! $this->broadcastWhen()) {
            return;
        }

        BusTallyChanged::dispatch($this->game, BusOverlay::snapshot($game));
    }
}
