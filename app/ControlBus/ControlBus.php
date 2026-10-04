<?php

namespace App\ControlBus;

use App\Events\BusActionPublished;
use App\Events\BusActionVetoed;
use App\Events\BusStateChanged;
use App\IdentityProvider;
use App\Jobs\ResolveBusWindow;
use App\Models\BusBallot;
use App\Models\BusControl;
use App\Models\BusPublication;
use App\Models\BusWindow;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The Chat Control Bus (#9): turns chat actions into game actions.
 *
 * - submit() takes one person's chat action (from !do) and records a ballot.
 *   In democracy and weighted-random it counts in the game's open vote
 *   window; in anarchy it is published at once, rate-limited per person.
 * - resolve() closes a due window and publishes its winner.
 * - publish() is the only way an action reaches adapters. It reads the kill
 *   switch and pause from the database every time (BusState::read), and the
 *   broadcast reads them again when it is sent.
 *
 * One person, one vote: ballots belong to users, so someone linked on several
 * platforms still has one vote per window, and subscribers weigh the same as
 * everyone else. Their only perk is cosmetic flair.
 *
 * Everything per game runs under a row lock on its bus_controls row, so two
 * chat jobs cannot open two windows or resolve one twice.
 */
class ControlBus
{
    public const FLAIR_SUBSCRIBER = 'subscriber';

    public function __construct(private Picker $picker) {}

    // --- Chat ---------------------------------------------------------------

    /**
     * Record a chat action. $text is everything after "!do": an action such
     * as "task Write the README", or "#2" to back option 2 of the open window.
     */
    public function submit(User $user, IdentityProvider $provider, string $messageId, string $text): Submission
    {
        $base = [
            'user_id' => $user->id,
            'provider' => $provider,
            'message_id' => $messageId !== '' ? $messageId : null,
            'subscriber' => $user->getHighestSubscription()->isSubscribed(),
        ];

        $game = Game::find(BusControl::query()->whereKey(BusControl::GLOBAL)->value('active_game'));
        if ($game === null) {
            return $this->refuse($base, BallotStatus::NoGame, 'No chat game is running right now.');
        }
        $base['game'] = $game->key;

        $reference = preg_match('/^#(\d{1,4})$/', trim($text), $m) ? (int) $m[1] : null;
        $action = null;
        if ($reference === null) {
            try {
                $action = $game->parse($text);
            } catch (InvalidArgumentException $e) {
                return $this->refuse($base, BallotStatus::Invalid, $e->getMessage());
            }
            $base += ['verb' => $action->verb, 'argument' => $action->argument, 'action_key' => $action->key()];
        }

        BusControl::for($game->key);

        return DB::transaction(function () use ($game, $base, $reference, $action, $user) {
            $this->lock($game);
            $state = BusState::read($game);

            if ($state->killed) {
                return $this->refuse($base, BallotStatus::Killed, 'The chat game is stopped.');
            }
            if ($state->paused) {
                return $this->refuse($base, BallotStatus::Paused, 'The chat game is paused.');
            }

            return $state->mode === Mode::Anarchy
                ? $this->submitAnarchy($game, $base, $reference, $action, $user)
                : $this->submitToWindow($game, $state->mode, $base, $reference, $action, $user);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function submitAnarchy(Game $game, array $base, ?int $reference, ?Action $action, User $user): Submission
    {
        if ($action === null) {
            return $this->refuse($base, BallotStatus::Invalid, "In anarchy every action runs: send it yourself, e.g. {$game->usage()}.");
        }

        $key = "bus-anarchy:{$game->key}:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, $game->anarchyActions)) {
            return $this->refuse($base, BallotStatus::RateLimited, 'Slow down: '.$game->anarchyActions.' actions per '.$game->anarchyPerSeconds.' seconds.');
        }
        RateLimiter::hit($key, $game->anarchyPerSeconds);

        $ballot = BusBallot::create($base + ['status' => BallotStatus::Published]);

        if ($this->publish($game, Mode::Anarchy, $action, 1, 1, null, $ballot) === null) {
            $ballot->update(['status' => BallotStatus::Killed]);

            return new Submission($ballot, 'The chat game is stopped.');
        }

        return new Submission($ballot, 'Sent: '.$action->label().'.');
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function submitToWindow(Game $game, Mode $mode, array $base, ?int $reference, ?Action $action, User $user): Submission
    {
        $window = $this->openWindow($game, $mode);

        if ($reference !== null) {
            $option = $window->ballots()->where('option_number', $reference)->orderBy('id')->first();
            if ($option === null) {
                return $this->refuse($base, BallotStatus::Invalid, "There is no option #{$reference} in this vote.");
            }
            $action = new Action((string) $option->verb, $option->argument);
            $base += ['verb' => $option->verb, 'argument' => $option->argument, 'action_key' => $option->action_key];
        }

        $base['window_id'] = $window->id;
        $key = $action->key();

        if ($window->ballots()->where('action_key', $key)->where('status', BallotStatus::Vetoed)->exists()) {
            return $this->refuse($base, BallotStatus::Vetoed, 'A moderator vetoed that option.');
        }

        $number = $window->ballots()->where('action_key', $key)->whereNotNull('option_number')->value('option_number')
            ?? ((int) $window->ballots()->max('option_number')) + 1;

        // One person, one vote per window: a new vote replaces their last one.
        $window->ballots()->where('user_id', $user->id)->where('status', BallotStatus::Counted)
            ->update(['status' => BallotStatus::Replaced]);

        $ballot = BusBallot::create($base + ['option_number' => $number, 'status' => BallotStatus::Counted]);

        return new Submission($ballot, "Voted for #{$number}: {$action->label()}.");
    }

    /**
     * The game's open window, resolving an overdue one first. Opens a window
     * if none is open, and schedules its resolution. Call under lock().
     */
    private function openWindow(Game $game, Mode $mode): BusWindow
    {
        $window = BusWindow::open()->where('game', $game->key)->latest('id')->first();

        if ($window !== null && $window->isDue()) {
            $this->resolveLocked($window, $game);
            $window = null;
        }

        if ($window === null) {
            $window = BusWindow::create([
                'game' => $game->key,
                'mode' => $mode,
                'status' => WindowStatus::Open,
                'opens_at' => now(),
                'closes_at' => now()->addSeconds($game->windowLengthSeconds()),
            ]);

            ResolveBusWindow::dispatch($window->id)->delay($window->closes_at)->afterCommit();
        }

        return $window;
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function refuse(array $base, BallotStatus $status, string $reply): Submission
    {
        return new Submission(BusBallot::create($base + ['status' => $status]), $reply);
    }

    // --- Windows ------------------------------------------------------------

    /**
     * Close a window if it is due, and publish its winner. Safe to call more
     * than once, from the delayed job and the scheduler alike.
     */
    public function resolve(BusWindow $window): ?BusPublication
    {
        $game = Game::find($window->game);
        if ($game === null) {
            $window->update(['status' => WindowStatus::Cancelled, 'resolved_at' => now()]);

            return null;
        }

        BusControl::for($game->key);

        return DB::transaction(function () use ($window, $game) {
            $this->lock($game);
            $fresh = BusWindow::whereKey($window->id)->lockForUpdate()->first();

            return $fresh !== null && $fresh->isDue() ? $this->resolveLocked($fresh, $game) : null;
        }, attempts: 3);
    }

    /**
     * Resolve every window that is due. Returns how many were closed.
     */
    public function resolveDue(): int
    {
        $due = BusWindow::open()->where('closes_at', '<=', now())->orderBy('id')->get();
        $due->each(fn (BusWindow $window) => $this->resolve($window));

        return $due->count();
    }

    /**
     * Tally a due window and publish its winner. Call under lock().
     */
    private function resolveLocked(BusWindow $window, Game $game): ?BusPublication
    {
        $counted = $window->ballots()->where('status', BallotStatus::Counted)->orderBy('id')->get();

        /** @var array<string, array{votes: int, first: BusBallot}> $options in order of first appearance */
        $options = [];
        foreach ($counted as $ballot) {
            $options[$ballot->action_key] ??= ['votes' => 0, 'first' => $ballot];
            $options[$ballot->action_key]['votes']++;
        }

        $total = $counted->count();
        $close = fn (WindowStatus $status) => $window->update(['status' => $status, 'resolved_at' => now(), 'total_votes' => $total]);

        if ($options === []) {
            $close(WindowStatus::Empty);

            return null;
        }

        $state = BusState::read($game);
        if (! $state->allowsPublishing()) {
            $close($state->killed ? WindowStatus::Killed : WindowStatus::Held);

            return null;
        }

        $winner = $window->mode === Mode::WeightedRandom
            ? $this->picker->pick(array_map(fn (array $o) => $o['votes'], $options))
            : $this->mostVotes($options);

        $first = $options[$winner]['first'];
        $publication = $this->publish(
            $game,
            $window->mode,
            new Action((string) $first->verb, $first->argument),
            $options[$winner]['votes'],
            $total,
            $window,
            $first,
        );

        $close($publication !== null ? WindowStatus::Resolved : WindowStatus::Killed);

        return $publication;
    }

    /**
     * The option with the most people behind it; a tie goes to the option
     * proposed first.
     *
     * @param  array<string, array{votes: int, first: BusBallot}>  $options  in order of first appearance
     */
    private function mostVotes(array $options): string
    {
        $best = null;
        foreach ($options as $key => $option) {
            if ($best === null || $option['votes'] > $options[$best]['votes']) {
                $best = $key;
            }
        }

        return (string) $best;
    }

    // --- Publishing ---------------------------------------------------------

    /**
     * Send an action to the game's adapters, unless the bus is killed or the
     * game paused. Both are read from the database here, on every call.
     */
    private function publish(Game $game, Mode $mode, Action $action, int $votes, int $total, ?BusWindow $window, BusBallot $proposer): ?BusPublication
    {
        if (! BusState::read($game)->allowsPublishing()) {
            return null;
        }

        $publication = BusPublication::create([
            'game' => $game->key,
            'mode' => $mode,
            'window_id' => $window?->id,
            'ballot_id' => $proposer->id,
            'verb' => $action->verb,
            'argument' => $action->argument === null ? null : (string) $action->argument,
            'votes' => $votes,
            'total_votes' => $total,
            // Cosmetic: shown by overlays, never counted.
            'flair' => $proposer->subscriber ? self::FLAIR_SUBSCRIBER : null,
        ]);

        BusActionPublished::dispatch($game->key, $publication->id);

        return $publication;
    }

    // --- Moderators ---------------------------------------------------------

    /**
     * Choose which game !do drives, or none. Open windows of other games are
     * cancelled.
     */
    public function setActiveGame(User $moderator, ?string $gameKey): void
    {
        Gate::forUser($moderator)->authorize('moderate');
        if ($gameKey !== null && Game::find($gameKey) === null) {
            throw new InvalidArgumentException("There is no game called {$gameKey}.");
        }

        DB::transaction(function () use ($moderator, $gameKey) {
            $global = BusControl::for(BusControl::GLOBAL);
            $previous = $global->active_game;
            $global->update(['active_game' => $gameKey]);

            BusWindow::open()->when($gameKey !== null, fn ($q) => $q->where('game', '!=', $gameKey))
                ->update(['status' => WindowStatus::Cancelled, 'resolved_at' => now()]);

            ModerationAction::record($moderator, 'bus.game', null, ['from' => $previous, 'to' => $gameKey]);

            foreach (array_unique(array_filter([$previous, $gameKey])) as $key) {
                $this->announceState($key);
            }
        });
    }

    public function setMode(User $moderator, string $gameKey, Mode $mode): void
    {
        Gate::forUser($moderator)->authorize('moderate');
        $game = $this->gameOrFail($gameKey);

        DB::transaction(function () use ($moderator, $game, $mode) {
            BusControl::for($game->key);
            $this->lock($game)->update(['mode' => $mode]);

            // Votes cast under the old rules do not carry over.
            BusWindow::open()->where('game', $game->key)
                ->update(['status' => WindowStatus::Cancelled, 'resolved_at' => now()]);

            ModerationAction::record($moderator, 'bus.mode', null, ['game' => $game->key, 'mode' => $mode->value]);
            $this->announceState($game->key);
        });
    }

    public function pause(User $moderator, string $gameKey): void
    {
        $this->setPaused($moderator, $gameKey, true);
    }

    public function resume(User $moderator, string $gameKey): void
    {
        $this->setPaused($moderator, $gameKey, false);
    }

    private function setPaused(User $moderator, string $gameKey, bool $paused): void
    {
        Gate::forUser($moderator)->authorize('moderate');
        $game = $this->gameOrFail($gameKey);

        DB::transaction(function () use ($moderator, $game, $paused) {
            BusControl::for($game->key);
            $this->lock($game)->update([
                'paused_at' => $paused ? now() : null,
                'paused_by_id' => $paused ? $moderator->id : null,
            ]);

            ModerationAction::record($moderator, $paused ? 'bus.paused' : 'bus.resumed', null, ['game' => $game->key]);
            $this->announceState($game->key);
        });
    }

    /**
     * The kill switch: nothing is published, for any game, until a broadcaster
     * resets it. Any moderator may throw it; $moderator is null from the CLI.
     */
    public function kill(?User $moderator, ?string $reason = null): void
    {
        if ($moderator !== null) {
            Gate::forUser($moderator)->authorize('moderate');
        }

        $this->setKilled($moderator, true, $reason);
    }

    /**
     * Reset the kill switch. Only a broadcaster may, or the CLI ($moderator null).
     */
    public function restore(?User $broadcaster): void
    {
        if ($broadcaster !== null) {
            Gate::forUser($broadcaster)->authorize('restoreBus');
        }

        $this->setKilled($broadcaster, false, null);
    }

    private function setKilled(?User $by, bool $killed, ?string $reason): void
    {
        DB::transaction(function () use ($by, $killed, $reason) {
            BusControl::for(BusControl::GLOBAL);
            BusControl::whereKey(BusControl::GLOBAL)->lockForUpdate()->first()?->update([
                'killed_at' => $killed ? now() : null,
                'killed_by_id' => $killed ? $by?->id : null,
            ]);

            if ($by !== null) {
                ModerationAction::record($by, $killed ? 'bus.killed' : 'bus.restored', null, array_filter(['reason' => $reason]));
            }

            foreach (array_keys(Game::all()) as $key) {
                $this->announceState($key);
            }
        });
    }

    /**
     * Veto an action that was already sent. Adapters are told to undo it, and
     * polling adapters see it marked vetoed.
     */
    public function vetoPublication(User $moderator, BusPublication $publication): void
    {
        Gate::forUser($moderator)->authorize('moderate');

        if ($publication->vetoed_at !== null) {
            return;
        }

        DB::transaction(function () use ($moderator, $publication) {
            $publication->update(['vetoed_at' => now(), 'vetoed_by_id' => $moderator->id]);
            ModerationAction::record($moderator, 'bus.vetoed', $publication, [
                'game' => $publication->game,
                'action' => trim($publication->verb.' '.$publication->argument),
            ]);
            BusActionVetoed::dispatch($publication->game, $publication->id);
        });
    }

    /**
     * Veto an option in an open window: its votes stop counting, and nobody
     * can back it again in this window.
     */
    public function vetoOption(User $moderator, BusWindow $window, string $actionKey): int
    {
        Gate::forUser($moderator)->authorize('moderate');
        $game = $this->gameOrFail($window->game);

        return DB::transaction(function () use ($moderator, $window, $actionKey, $game) {
            $this->lock($game);

            $vetoed = $window->ballots()
                ->where('action_key', $actionKey)
                ->whereIn('status', [BallotStatus::Counted, BallotStatus::Replaced])
                ->update(['status' => BallotStatus::Vetoed]);

            ModerationAction::record($moderator, 'bus.option_vetoed', $window, ['game' => $window->game, 'action_key' => $actionKey, 'ballots' => $vetoed]);

            return $vetoed;
        });
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * Lock the game's control row for the rest of the transaction. Everything
     * that opens, fills or closes a window for the game takes this lock first.
     */
    private function lock(Game $game): BusControl
    {
        return BusControl::whereKey($game->key)->lockForUpdate()->firstOrFail();
    }

    private function gameOrFail(string $key): Game
    {
        return Game::find($key) ?? throw new InvalidArgumentException("There is no game called {$key}.");
    }

    private function announceState(string $gameKey): void
    {
        $game = Game::find($gameKey);
        if ($game === null) {
            return;
        }

        $state = BusState::read($game);
        BusStateChanged::dispatch($game->key, $state->killed, $state->paused, $state->mode->value, $state->activeGame === $game->key);
    }
}
