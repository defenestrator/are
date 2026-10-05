<?php

namespace App\ControlBus;

use App\Models\BusApproval;
use App\Models\BusBallot;
use App\Models\BusControl;
use App\Models\BusPublication;
use App\Models\BusWindow;
use App\QuestionQueue;
use Illuminate\Support\Carbon;

/**
 * What the on-stream bus overlay (/overlay/bus, #138) may show about a game,
 * and the payload of the bus.tally broadcast. Both come from here, so the
 * privacy rules live in one place:
 *
 * - No user data: no ids, names, platforms or message ids. Options are
 *   identified by their number in the window.
 * - No free text before a moderator approves it. A free-text verb's
 *   argument (Orkestera's task) is shown only once a moderator has approved
 *   that action, even for a text verb configured without approval. Until
 *   then, `label` is null and the overlay shows only the verb.
 * - While the kill switch is on, nothing but the state.
 */
final class BusOverlay
{
    /** How long a published or rejected result stays on the overlay. */
    public const RESULT_SECONDS = 20;

    /**
     * The game the overlay shows: the one moderators set running on /bus.
     * Read fresh, like BusState.
     */
    public static function activeGame(): ?Game
    {
        return Game::find(BusControl::find(BusControl::GLOBAL)?->active_game);
    }

    /**
     * Whether the kill switch is on, with or without a running game.
     */
    public static function killed(): bool
    {
        return ! config('bus.enabled') || BusControl::find(BusControl::GLOBAL)?->killed_at !== null;
    }

    /**
     * What the overlay renders: the running game's snapshot, or, with no game
     * running, only whether the kill switch is on.
     *
     * @return array<string, mixed>|null null when there is nothing to show
     */
    public static function forOverlay(): ?array
    {
        $game = self::activeGame();

        if ($game !== null) {
            return self::snapshot($game);
        }

        return self::killed() ? ['game' => null, 'killed' => true] : null;
    }

    /**
     * @return array{version: int, game: array{key: string, label: string}, running: bool, killed: bool, paused: bool, mode: string, mode_label: string, window: array<string, mixed>|null, awaiting: array{verb: string, votes: int, total: int, count: int}|null, result: array<string, mixed>|null}
     */
    public static function snapshot(Game $game): array
    {
        $state = BusState::read($game);
        $base = [
            'version' => (int) floor(microtime(true) * 1000),
            'game' => ['key' => $game->key, 'label' => $game->label],
            'running' => $state->activeGame === $game->key,
            'killed' => $state->killed,
            'paused' => $state->paused,
            'mode' => $state->mode->value,
            'mode_label' => $state->mode->label(),
            'window' => null,
            'awaiting' => null,
            'result' => null,
        ];

        if ($state->killed) {
            return $base;
        }

        return [
            ...$base,
            'window' => self::window($game),
            'awaiting' => self::awaiting($game),
            'result' => self::result($game),
        ];
    }

    /**
     * Whether this verb's argument is free text, which stays hidden until a
     * moderator approves it.
     */
    public static function isFreeText(Game $game, ?string $verb): bool
    {
        return ($game->verbs[$verb ?? '']['argument'] ?? 'none') === 'text';
    }

    /**
     * @return array{id: int, seconds_left: int, closes_at: string, total: int, options: list<array{number: int, verb: string, label: string|null, votes: int, vetoed: bool}>}|null
     */
    private static function window(Game $game): ?array
    {
        $window = BusWindow::open()->where('game', $game->key)->latest('id')->first();

        if ($window === null) {
            return null;
        }

        $options = BusBallot::query()
            ->where('window_id', $window->id)
            ->whereNotNull('option_number')
            ->orderBy('option_number')
            ->orderBy('id')
            ->get(['option_number', 'verb', 'argument', 'status'])
            ->groupBy('option_number')
            ->map(function ($ballots) use ($game) {
                $first = $ballots->first();

                return [
                    'number' => (int) $first->option_number,
                    'verb' => (string) $first->verb,
                    'label' => self::label($game, $first->verb, $first->argument, approved: false),
                    'votes' => $ballots->where('status', BallotStatus::Counted)->count(),
                    'vetoed' => $ballots->contains('status', BallotStatus::Vetoed),
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $window->id,
            'seconds_left' => max(0, (int) ceil(now()->diffInMilliseconds($window->closes_at, false) / 1000)),
            'closes_at' => $window->closes_at->toIso8601ZuluString(),
            'total' => (int) array_sum(array_column($options, 'votes')),
            'options' => $options,
        ];
    }

    /**
     * A free-text winner waiting for a moderator. Only its verb and counts:
     * its text is exactly what has not been approved yet.
     *
     * @return array{verb: string, votes: int, total: int, count: int}|null
     */
    private static function awaiting(Game $game): ?array
    {
        $pending = BusApproval::pending()->where('game', $game->key)->orderBy('id');
        $count = (clone $pending)->count();
        $oldest = $pending->first();

        if ($oldest === null) {
            return null;
        }

        return ['verb' => $oldest->verb, 'votes' => $oldest->votes, 'total' => $oldest->total_votes, 'count' => $count];
    }

    /**
     * The latest outcome, for RESULT_SECONDS: an action published to the
     * game, or a free-text winner a moderator rejected.
     *
     * @return array{status: string, verb: string, label: string|null, votes: int, total: int, seconds_left: int}|null
     */
    private static function result(Game $game): ?array
    {
        $since = now()->subSeconds(self::RESULT_SECONDS);

        $publication = BusPublication::where('game', $game->key)
            ->whereNull('vetoed_at')
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->first();

        $rejection = BusApproval::where('game', $game->key)
            ->where('status', ApprovalStatus::Rejected)
            ->where('decided_at', '>=', $since)
            ->latest('decided_at')
            ->first();

        $secondsLeft = fn (Carbon $at) => max(1, self::RESULT_SECONDS - (int) $at->diffInSeconds(now()));
        // BusApproval casts decided_at to a datetime but does not declare it.
        $rejectedAt = $rejection === null ? null : Carbon::parse($rejection->getAttribute('decided_at'));

        if ($rejection !== null && $rejectedAt !== null && ($publication === null || $rejectedAt->gt($publication->created_at))) {
            return [
                'status' => 'rejected',
                'verb' => $rejection->verb,
                'label' => null,
                'votes' => $rejection->votes,
                'total' => $rejection->total_votes,
                'seconds_left' => $secondsLeft($rejectedAt),
            ];
        }

        if ($publication === null) {
            return null;
        }

        $approved = BusApproval::where('publication_id', $publication->id)
            ->where('status', ApprovalStatus::Approved)
            ->exists();

        return [
            'status' => 'published',
            'verb' => $publication->verb,
            'label' => self::label($game, $publication->verb, $publication->argument, $approved),
            'votes' => (int) $publication->votes,
            'total' => (int) $publication->total_votes,
            'seconds_left' => $secondsLeft($publication->created_at),
        ];
    }

    /**
     * The text after the verb, if it may be shown: a fixed verb's argument
     * (a number or a choice) always, and free text only once approved.
     */
    private static function label(Game $game, ?string $verb, ?string $argument, bool $approved): ?string
    {
        $argument = trim((string) $argument);

        if ($argument === '') {
            return null;
        }

        if (self::isFreeText($game, $verb) && ! $approved) {
            return null;
        }

        return QuestionQueue::clean($argument);
    }
}
