<?php

namespace App\ControlBus;

use App\Models\BusControl;

/**
 * The kill switch, pause and mode for a game, read from the database at the
 * moment of asking. Nothing here is cached or memoised: a moderator's switch
 * must stop the very next publish, in this process or any other.
 */
final readonly class BusState
{
    public function __construct(
        public bool $killed,
        public bool $paused,
        public Mode $mode,
        public ?string $activeGame,
        public int $replayFloor = 0,
    ) {}

    public static function read(Game $game): self
    {
        $rows = BusControl::query()
            ->whereIn('scope', [BusControl::GLOBAL, $game->key])
            ->get()
            ->keyBy('scope');

        $global = $rows->get(BusControl::GLOBAL);
        $own = $rows->get($game->key);

        return new self(
            killed: ! config('bus.enabled') || $global?->killed_at !== null,
            paused: $own?->paused_at !== null,
            mode: $own->mode ?? $game->defaultMode,
            activeGame: $global?->active_game,
            replayFloor: (int) ($own->replay_floor ?? 0),
        );
    }

    /**
     * Whether an action may go out to adapters right now.
     */
    public function allowsPublishing(): bool
    {
        return ! $this->killed && ! $this->paused;
    }
}
