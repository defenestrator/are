<?php

namespace App\ControlBus;

use App\Events\BusActionPublished;
use App\Events\BusActionVetoed;
use App\Events\BusStateChanged;
use App\Events\KillSwitchThrown;
use App\IdentityProvider;
use App\Jobs\ResolveBusWindow;
use App\Models\BusApproval;
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
 *   window; in anarchy it is released at once, rate-limited per person.
 * - resolve() closes a due window and releases its winner.
 * - Releasing publishes a fixed-verb action straight away. A free-text action
 *   (Orkestera's task, whose text goes to AI agents) waits as a BusApproval
 *   until a moderator approves it; rejected or left to expire, it is never
 *   published.
 * - publish() is the only way an action reaches adapters. It reads the kill
 *   switch and pause from the database every time (BusState::read), and the
 *   broadcast reads them again when it is sent.
 * - The kill switch voids what adapters have not run yet: every action not
 *   yet delivered, and those delivered in the last bus.kill_undo_seconds, are
 *   vetoed and adapters told to undo them. Nothing comes back on restore.
 *
 * One person, one vote: ballots belong to users, so someone linked on several
 * platforms still has one vote per window, and subscribers weigh the same as
 * everyone else. Their only perk is cosmetic flair.
 *
 * Locks, always taken in this order, so they cannot deadlock:
 *   1. the game's bus_controls row, FOR UPDATE: everything that opens, fills,
 *      closes or decides on a game's windows and approvals;
 *   2. the global row, FOR SHARE, by anything that may publish; the kill
 *      switch takes it FOR UPDATE, so a kill waits for publishes in flight
 *      and then voids them, and no publish starts until the kill commits;
 *   3. window and approval rows.
 */
class ControlBus
{
    public const FLAIR_SUBSCRIBER = 'subscriber';

    public function __construct(private Picker $picker) {}

    // --- Chat ---------------------------------------------------------------

    /**
     * Record a chat action. $text is everything after "!do": an action such
     * as "task Write the README", or "#2" to back option 2 of the open window.
     *
     * An agent (#10) acts through its service user, with no chat platform
     * ($provider null) and its own id: it gets one vote, like a person, and
     * its free text waits for a moderator like anyone's.
     */
    public function submit(User $user, ?IdentityProvider $provider, string $messageId, string $text, ?int $agentId = null): Submission
    {
        $base = [
            'user_id' => $user->id,
            'agent_id' => $agentId,
            'provider' => $provider,
            'message_id' => $messageId !== '' ? $messageId : null,
            'subscriber' => $user->getHighestSubscription()->isSubscribed(),
        ];

        $game = Game::find(BusControl::query()->whereKey(BusControl::GLOBAL)->value('active_game'));
        if ($game === null) {
            return $this->refuse($base, BallotStatus::NoGame, Submission::NO_GAME);
        }
        $base['game'] = $game->key;

        $reference = preg_match('/^#(\d{1,4})$/', trim($text), $m) ? (int) $m[1] : null;
        $action = null;
        if ($reference === null) {
            try {
                $action = $game->parse($text);
            } catch (InvalidArgumentException $e) {
                return $this->refuse($base, BallotStatus::Invalid, Submission::INVALID);
            }
            $base += ['verb' => $action->verb, 'argument' => $action->argument, 'action_key' => $action->key()];
        }

        $this->ensureControls($game);

        return DB::transaction(function () use ($game, $base, $reference, $action, $user) {
            $this->lock($game);
            $this->shareGlobal();
            $state = BusState::read($game);

            if ($state->killed) {
                return $this->refuse($base, BallotStatus::Killed, Submission::KILLED);
            }
            if ($state->paused) {
                return $this->refuse($base, BallotStatus::Paused, Submission::PAUSED);
            }

            return $state->mode === Mode::Anarchy
                ? $this->submitAnarchy($game, $base, $action, $user)
                : $this->submitToWindow($game, $state->mode, $base, $reference, $action, $user);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function submitAnarchy(Game $game, array $base, ?Action $action, User $user): Submission
    {
        if ($action === null) {
            return $this->refuse($base, BallotStatus::Invalid, Submission::ANARCHY_REFERENCE);
        }

        $key = "bus-anarchy:{$game->key}:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, $game->anarchyActions)) {
            return $this->refuse($base, BallotStatus::RateLimited, Submission::RATE_LIMITED);
        }
        RateLimiter::hit($key, $game->anarchyPerSeconds);

        $needsApproval = $game->requiresApproval($action->verb);
        $ballot = BusBallot::create($base + ['status' => $needsApproval ? BallotStatus::PendingApproval : BallotStatus::Published]);

        if ($needsApproval) {
            $this->awaitApproval($game, Mode::Anarchy, $action, 1, 1, null, $ballot);

            return new Submission($ballot, Submission::ACCEPTED);
        }

        if ($this->publish($game, Mode::Anarchy, $action, 1, 1, null, $ballot) === null) {
            $ballot->update(['status' => BallotStatus::Killed]);

            return new Submission($ballot, Submission::KILLED);
        }

        return new Submission($ballot, Submission::ACCEPTED);
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
                return $this->refuse($base, BallotStatus::Invalid, Submission::NO_SUCH_OPTION);
            }
            $action = new Action((string) $option->verb, $option->argument);
            $base += ['verb' => $option->verb, 'argument' => $option->argument, 'action_key' => $option->action_key];
        }

        $base['window_id'] = $window->id;
        $key = $action->key();

        if ($window->ballots()->where('action_key', $key)->where('status', BallotStatus::Vetoed)->exists()) {
            return $this->refuse($base, BallotStatus::Vetoed, Submission::VETOED_OPTION);
        }

        // Someone who backed a vetoed option sits out the rest of the window,
        // so a veto cannot be dodged by retyping the option some other way.
        if ($window->ballots()->where('user_id', $user->id)->where('status', BallotStatus::Vetoed)->exists()) {
            return $this->refuse($base, BallotStatus::Vetoed, Submission::SAT_OUT);
        }

        $number = $window->ballots()->where('action_key', $key)->whereNotNull('option_number')->value('option_number')
            ?? ((int) $window->ballots()->max('option_number')) + 1;

        // One person, one vote per window: a new vote replaces their last one.
        $window->ballots()->where('user_id', $user->id)->where('status', BallotStatus::Counted)
            ->update(['status' => BallotStatus::Replaced]);

        $ballot = BusBallot::create($base + ['option_number' => $number, 'status' => BallotStatus::Counted]);

        return new Submission($ballot, Submission::ACCEPTED);
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
    private function refuse(array $base, BallotStatus $status, string $reason): Submission
    {
        return new Submission(BusBallot::create($base + ['status' => $status]), $reason);
    }

    // --- Windows ------------------------------------------------------------

    /**
     * Close a window if it is due, and release its winner. Safe to call more
     * than once, from the delayed job and the scheduler alike.
     */
    public function resolve(BusWindow $window): BusPublication|BusApproval|null
    {
        $game = Game::find($window->game);
        if ($game === null) {
            $window->update(['status' => WindowStatus::Cancelled, 'resolved_at' => now()]);

            return null;
        }

        $this->ensureControls($game);

        return DB::transaction(function () use ($window, $game) {
            $this->lock($game);
            $this->shareGlobal();
            $fresh = BusWindow::whereKey($window->id)->lockForUpdate()->first();

            return $fresh !== null && $fresh->isDue() ? $this->resolveLocked($fresh, $game) : null;
        }, attempts: 3);
    }

    /**
     * Resolve every window that is due, and reject approvals nobody decided
     * in time. Returns how many windows were closed.
     */
    public function resolveDue(): int
    {
        $due = BusWindow::open()->where('closes_at', '<=', now())->orderBy('id')->get();
        $due->each(fn (BusWindow $window) => $this->resolve($window));

        $this->expireApprovals();

        return $due->count();
    }

    /**
     * Tally a due window and release its winner. Call under lock() and
     * shareGlobal().
     */
    private function resolveLocked(BusWindow $window, Game $game): BusPublication|BusApproval|null
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
        $action = new Action((string) $first->verb, $first->argument);

        if ($game->requiresApproval($action->verb)) {
            $close(WindowStatus::AwaitingApproval);

            return $this->awaitApproval($game, $window->mode, $action, $options[$winner]['votes'], $total, $window, $first);
        }

        $publication = $this->publish($game, $window->mode, $action, $options[$winner]['votes'], $total, $window, $first);
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

    // --- Approval of free-text actions --------------------------------------

    private function awaitApproval(Game $game, Mode $mode, Action $action, int $votes, int $total, ?BusWindow $window, BusBallot $proposer): BusApproval
    {
        return BusApproval::create([
            'game' => $game->key,
            'mode' => $mode,
            'window_id' => $window?->id,
            'ballot_id' => $proposer->id,
            'verb' => $action->verb,
            'argument' => $action->argument === null ? null : (string) $action->argument,
            'action_key' => $action->key(),
            'votes' => $votes,
            'total_votes' => $total,
            'flair' => $proposer->subscriber ? self::FLAIR_SUBSCRIBER : null,
            'status' => ApprovalStatus::Pending,
            'expires_at' => now()->addSeconds(max(1, (int) config('bus.approval_timeout_seconds'))),
        ]);
    }

    /**
     * A moderator approves a free-text action: it is published now, if the
     * bus is running and the approval has not expired.
     *
     * @throws InvalidArgumentException with a message for the moderator
     */
    public function approve(User $moderator, BusApproval $approval): BusPublication
    {
        Gate::forUser($moderator)->authorize('moderate');
        $game = $this->gameOrFail($approval->game);
        $this->ensureControls($game);

        $publication = DB::transaction(function () use ($moderator, $approval, $game) {
            $this->lock($game);
            $this->shareGlobal();
            $fresh = BusApproval::whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ApprovalStatus::Pending) {
                throw new InvalidArgumentException('That action was already '.$fresh->status->value.'.');
            }
            if ($fresh->expires_at->lte(now())) {
                // Committed, then reported below: throwing here would roll it back.
                $this->decide($fresh, ApprovalStatus::Rejected, null, 'timed out');

                return null;
            }

            $proposer = BusBallot::find($fresh->ballot_id);
            $publication = $proposer === null ? null : $this->publish(
                $game,
                $fresh->mode,
                new Action($fresh->verb, $fresh->argument),
                $fresh->votes,
                $fresh->total_votes,
                $fresh->window,
                $proposer,
            );

            if ($publication === null) {
                throw new InvalidArgumentException('The bus is stopped or the game is paused, so nothing can be published.');
            }

            $this->decide($fresh, ApprovalStatus::Approved, $moderator, null, $publication);
            if ($proposer->status === BallotStatus::PendingApproval) {
                $proposer->update(['status' => BallotStatus::Published]);
            }
            ModerationAction::record($moderator, 'bus.approved', $fresh, ['game' => $fresh->game, 'action' => $fresh->label(), 'publication_id' => $publication->id]);

            return $publication;
        }, attempts: 3);

        return $publication ?? throw new InvalidArgumentException('That action timed out before it was approved.');
    }

    /**
     * A moderator rejects a free-text action: it is never published, and its
     * ballots count as vetoed.
     */
    public function reject(User $moderator, BusApproval $approval, ?string $reason = null): void
    {
        Gate::forUser($moderator)->authorize('moderate');
        $game = $this->gameOrFail($approval->game);
        $this->ensureControls($game);

        DB::transaction(function () use ($moderator, $approval, $game, $reason) {
            $this->lock($game);
            $fresh = BusApproval::whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ApprovalStatus::Pending) {
                return;
            }

            $this->decide($fresh, ApprovalStatus::Rejected, $moderator, $reason);
            ModerationAction::record($moderator, 'bus.rejected', $fresh, array_filter(['game' => $fresh->game, 'action' => $fresh->label(), 'reason' => $reason]));
        }, attempts: 3);
    }

    /**
     * Reject every approval nobody decided before it expired.
     */
    public function expireApprovals(): int
    {
        $expired = 0;

        BusApproval::pending()->where('expires_at', '<=', now())->orderBy('id')->get()
            ->each(function (BusApproval $approval) use (&$expired) {
                $game = Game::find($approval->game);
                if ($game === null) {
                    return;
                }
                $this->ensureControls($game);

                DB::transaction(function () use ($approval, $game, &$expired) {
                    $this->lock($game);
                    $fresh = BusApproval::whereKey($approval->id)->lockForUpdate()->first();
                    if ($fresh?->status === ApprovalStatus::Pending && $fresh->expires_at->lte(now())) {
                        $this->decide($fresh, ApprovalStatus::Rejected, null, 'timed out');
                        $expired++;
                    }
                }, attempts: 3);
            });

        return $expired;
    }

    private function decide(BusApproval $approval, ApprovalStatus $status, ?User $by, ?string $reason, ?BusPublication $publication = null): void
    {
        $approval->update([
            'status' => $status,
            'decided_by_id' => $by?->id,
            'decided_at' => now(),
            'reason' => $reason,
            'publication_id' => $publication?->id,
        ]);

        if ($status === ApprovalStatus::Approved) {
            $approval->window?->update(['status' => WindowStatus::Resolved]);

            return;
        }

        $approval->window?->update(['status' => $status === ApprovalStatus::Cancelled ? WindowStatus::Cancelled : WindowStatus::Rejected]);

        if ($status !== ApprovalStatus::Rejected) {
            return;
        }

        // The rejected option's backers count as vetoed, like a vetoed option.
        if ($approval->window_id !== null) {
            BusBallot::where('window_id', $approval->window_id)
                ->where('action_key', $approval->action_key)
                ->whereIn('status', [BallotStatus::Counted, BallotStatus::Replaced])
                ->update(['status' => BallotStatus::Vetoed]);
        } elseif ($approval->ballot_id !== null) {
            BusBallot::whereKey($approval->ballot_id)->update(['status' => BallotStatus::Vetoed]);
        }
    }

    // --- Publishing ---------------------------------------------------------

    /**
     * Send an action to the game's adapters, unless the bus is killed or the
     * game paused. Both are read from the database here, on every call. Call
     * inside a transaction holding shareGlobal(), so a kill cannot commit
     * between this check and the publication.
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

        BusControl::for(BusControl::GLOBAL);

        DB::transaction(function () use ($moderator, $gameKey) {
            $global = BusControl::whereKey(BusControl::GLOBAL)->lockForUpdate()->firstOrFail();
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
        $this->ensureControls($game);

        DB::transaction(function () use ($moderator, $game, $mode) {
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
        $this->ensureControls($game);

        DB::transaction(function () use ($moderator, $game, $paused) {
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
     * resets it, and what adapters have not run yet is voided. Any moderator
     * may throw it; $moderator is null from the CLI, which names its $command
     * for the audit log (#149).
     */
    public function kill(?User $moderator, ?string $reason = null, ?string $command = null): void
    {
        if ($moderator !== null) {
            Gate::forUser($moderator)->authorize('moderate');
        }

        $this->setKilled($moderator, true, $reason, $command);
    }

    /**
     * Reset the kill switch. Only a broadcaster may, or the CLI ($moderator null).
     * Nothing voided by the kill comes back.
     */
    public function restore(?User $broadcaster, ?string $command = null): void
    {
        if ($broadcaster !== null) {
            Gate::forUser($broadcaster)->authorize('restoreBus');
        }

        $this->setKilled($broadcaster, false, null, $command);
    }

    private function setKilled(?User $by, bool $killed, ?string $reason, ?string $command = null): void
    {
        BusControl::for(BusControl::GLOBAL);

        DB::transaction(function () use ($by, $killed, $reason, $command) {
            // Waits for any publish in flight (they hold the row FOR SHARE),
            // and blocks new ones until this commits.
            BusControl::whereKey(BusControl::GLOBAL)->lockForUpdate()->firstOrFail()->update([
                'killed_at' => $killed ? now() : null,
                'killed_by_id' => $killed ? $by?->id : null,
            ]);

            $voided = $killed ? $this->voidPending($by) : ['publications' => 0, 'approvals' => 0, 'windows' => 0];

            ModerationAction::record($by, $killed ? 'bus.killed' : 'bus.restored', null, array_filter([
                'via' => $by === null ? 'cli' : null,
                'command' => $by === null ? $command : null,
                'reason' => $reason,
            ]) + ($killed ? ['voided' => $voided] : []));

            foreach (array_keys(Game::all()) as $key) {
                $this->announceState($key);
            }

            // After commit: the VTuber agent is refused from here on (it
            // reads the same switch), and CutToIntermission cuts the scene.
            if ($killed) {
                KillSwitchThrown::dispatch($by?->id, $reason);
            }
        });
    }

    /**
     * Void everything the kill switch must stop: open votes, approvals still
     * waiting, and published actions adapters have not run (undelivered, or
     * delivered within bus.kill_undo_seconds). Vetoed actions are announced,
     * so an adapter that ran one can undo it, and polling never returns them.
     *
     * @return array{publications: int, approvals: int, windows: int}
     */
    private function voidPending(?User $by): array
    {
        // The watermark: whatever was published before this kill is never
        // served again, even to an adapter that was offline or restarts
        // from an old cursor. Runs under the global row FOR UPDATE, so no
        // publish can slip in below it.
        foreach (array_keys(Game::all()) as $key) {
            BusControl::for($key)->update(['replay_floor' => (int) BusPublication::where('game', $key)->max('id')]);
        }

        $windows = BusWindow::open()->update(['status' => WindowStatus::Cancelled, 'resolved_at' => now()]);

        $approvals = 0;
        BusApproval::pending()->orderBy('id')->get()->each(function (BusApproval $approval) use (&$approvals) {
            $this->decide($approval, ApprovalStatus::Cancelled, null, 'kill switch');
            $approvals++;
        });

        $since = now()->subSeconds(max(0, (int) config('bus.kill_undo_seconds')));
        $publications = BusPublication::whereNull('vetoed_at')
            ->where(fn ($q) => $q->whereNull('delivered_at')->orWhere('delivered_at', '>=', $since))
            ->orderBy('id')
            ->get(['id', 'game']);

        if ($publications->isNotEmpty()) {
            BusPublication::whereKey($publications->pluck('id'))->update([
                'vetoed_at' => now(),
                'vetoed_by_id' => $by?->id,
                'veto_reason' => 'kill',
            ]);

            $publications->each(fn (BusPublication $p) => BusActionVetoed::dispatch($p->game, $p->id));
        }

        return ['publications' => $publications->count(), 'approvals' => $approvals, 'windows' => $windows];
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
            $publication->update(['vetoed_at' => now(), 'vetoed_by_id' => $moderator->id, 'veto_reason' => 'moderator']);
            ModerationAction::record($moderator, 'bus.vetoed', $publication, [
                'game' => $publication->game,
                'action' => trim($publication->verb.' '.$publication->argument),
            ]);
            BusActionVetoed::dispatch($publication->game, $publication->id);
        });
    }

    /**
     * Veto an option in an open window: its votes stop counting, nobody can
     * back it again in this window, and its backers sit out the rest of it.
     */
    public function vetoOption(User $moderator, BusWindow $window, string $actionKey): int
    {
        Gate::forUser($moderator)->authorize('moderate');
        $game = $this->gameOrFail($window->game);

        // Accept the key in any spelling ("task:Delete the repo"): match it
        // the way ballots are keyed.
        [$verb, $argument] = array_pad(explode(':', $actionKey, 2), 2, null);
        $actionKey = (new Action(mb_strtolower((string) $verb), $argument))->key();

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
     * Create the game's and the global control rows if missing. Outside any
     * lock: createOrFirst is safe when two requests race.
     */
    private function ensureControls(Game $game): void
    {
        BusControl::for(BusControl::GLOBAL);
        BusControl::for($game->key);
    }

    /**
     * Lock the game's control row for the rest of the transaction (lock 1).
     */
    private function lock(Game $game): BusControl
    {
        return BusControl::whereKey($game->key)->lockForUpdate()->firstOrFail();
    }

    /**
     * Hold the global row FOR SHARE for the rest of the transaction (lock 2),
     * so the kill switch cannot change between reading it and publishing.
     */
    private function shareGlobal(): void
    {
        BusControl::whereKey(BusControl::GLOBAL)->sharedLock()->first();
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
