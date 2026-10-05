<?php

namespace App;

use App\Enums\SongRequestSource;
use App\Enums\SongRequestStatus;
use App\Exceptions\SongRequestRejected;
use App\Jobs\RefundChannelPointRedemption;
use App\Models\ChannelPointRedemption;
use App\Models\ModerationAction;
use App\Models\MusicPlayerToken;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The song request queue: the rules for requesting a track (shared by !song
 * and the channel-point reward) and the moderator actions that play, skip and
 * clear requests. Every song_requests write goes through here.
 *
 * Only Track::requestable() tracks can be requested, so a request can never
 * play something that would get the stream claimed.
 */
class SongRequests
{
    /**
     * Find the requestable track a viewer means: "#12" or "12" by number,
     * otherwise by title, ignoring case. An exact title wins over a partial
     * one. The catalogue is small, so this matches in PHP, which keeps it
     * Unicode-aware and identical on SQLite and Postgres.
     *
     * @throws SongRequestRejected
     */
    public static function resolve(string $query): Track
    {
        $query = QuestionQueue::clean($query);

        if (preg_match('/^#?(\d{1,18})$/', $query, $m) && ($track = Track::requestable()->find((int) $m[1]))) {
            return $track;
        }

        $needle = mb_strtolower(ltrim($query, '#'));
        if ($needle === '') {
            throw SongRequestRejected::notFound();
        }

        $tracks = Track::requestable()->orderBy('title')->orderBy('id')->get();

        $exact = $tracks->filter(fn (Track $t) => mb_strtolower($t->title) === $needle);
        $matches = $exact->isNotEmpty() ? $exact : $tracks->filter(fn (Track $t) => str_contains(mb_strtolower($t->title), $needle));

        return match ($matches->count()) {
            0 => throw SongRequestRejected::notFound(),
            1 => $matches->first(),
            default => throw SongRequestRejected::ambiguous(
                $matches->take(3)->map(fn (Track $t) => (int) $t->id)->values()->all(),
            ),
        };
    }

    /**
     * Add a track to the queue. A track that is already queued or playing is
     * not added twice. Chat requests count against music.requests_per_user;
     * channel-point requests are paid for with points and do not.
     *
     * @throws SongRequestRejected
     */
    public static function request(
        Track $track,
        ?User $requester,
        string $requesterName,
        SongRequestSource $source,
        ?ChannelPointRedemption $redemption = null,
    ): SongRequest {
        if ($requester?->isBanned()) {
            throw SongRequestRejected::banned();
        }

        return DB::transaction(function () use ($track, $requester, $requesterName, $source, $redemption) {
            // Lock the requester's row first (#130, as #94 does for questions):
            // their second request, even for another track, waits here until
            // the first has committed and then counts it against the cap.
            // Always user before track, so the lock order cannot deadlock.
            if ($requester !== null) {
                User::whereKey($requester->id)->lockForUpdate()->first();
            }

            // Locking the track serialises requests for it, so two at once
            // cannot both pass the duplicate check on Postgres.
            $locked = Track::requestable()->lockForUpdate()->find($track->id);
            if ($locked === null) {
                throw SongRequestRejected::notFound();
            }

            if (SongRequest::open()->where('track_id', $locked->id)->exists()) {
                throw SongRequestRejected::alreadyQueued((int) $locked->id);
            }

            $limit = (int) config('music.requests_per_user');
            if ($source === SongRequestSource::Chat && $requester !== null
                && SongRequest::queued()->where('requester_id', $requester->id)->count() >= $limit) {
                throw SongRequestRejected::limitReached($limit);
            }

            return SongRequest::create([
                'track_id' => $locked->id,
                'requester_id' => $requester?->id,
                'requester_name' => $requesterName,
                'source' => $source,
                'channel_point_redemption_id' => $redemption?->id,
                'status' => SongRequestStatus::Queued,
            ]);
        });
    }

    /**
     * The request on air, with its track.
     */
    public static function nowPlaying(): ?SongRequest
    {
        return SongRequest::with('track')
            ->where('status', SongRequestStatus::Playing->value)
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    /**
     * Put a queued request on air. Whatever was playing counts as played.
     */
    public static function play(User $moderator, SongRequest $request): SongRequest
    {
        Gate::forUser($moderator)->authorize('moderate');

        if ($request->status !== SongRequestStatus::Queued) {
            throw new InvalidArgumentException('Only a queued request can be played.');
        }

        return DB::transaction(function () use ($request) {
            self::finishPlaying();
            $request->update(['status' => SongRequestStatus::Playing, 'started_at' => now()]);

            return $request;
        });
    }

    /**
     * Play the oldest queued request, if there is one.
     */
    public static function playNext(User $moderator): ?SongRequest
    {
        Gate::forUser($moderator)->authorize('moderate');

        $next = SongRequest::queued()->first();

        return $next === null ? null : self::play($moderator, $next);
    }

    /**
     * Mark the request on air as played.
     */
    public static function finish(User $moderator): void
    {
        Gate::forUser($moderator)->authorize('moderate');

        self::finishPlaying();
    }

    /**
     * The "mark playing" step (#136): the request on air counts as played and
     * the oldest queued request goes on air. Returns the new one, or null
     * when the queue is empty (then nothing is on air). Audited.
     */
    public static function advance(User $moderator): ?SongRequest
    {
        Gate::forUser($moderator)->authorize('moderate');

        return self::advanceQueue(fn (?SongRequest $next, ?SongRequest $finished) => ModerationAction::record(
            $moderator, 'song_request.advanced', $next, self::auditDetails($next, $finished),
        ));
    }

    /**
     * advance(), for a local player that authenticated with its token through
     * POST /music/requests/advance. Audited under the player's name.
     */
    public static function advanceForPlayer(MusicPlayerToken $player): ?SongRequest
    {
        return self::advanceQueue(fn (?SongRequest $next, ?SongRequest $finished) => ModerationAction::recordForPlayer(
            $player, 'song_request.advanced', $next, self::auditDetails($next, $finished),
        ));
    }

    /**
     * finish(), for a local player. Audited under the player's name.
     */
    public static function finishForPlayer(MusicPlayerToken $player): void
    {
        DB::transaction(function () use ($player) {
            $finished = SongRequest::where('status', SongRequestStatus::Playing->value)->lockForUpdate()->first();
            self::finishPlaying();
            ModerationAction::recordForPlayer($player, 'song_request.finished', $finished, self::auditDetails(null, $finished));
        });
    }

    /**
     * @param  Closure(?SongRequest, ?SongRequest): mixed  $audit
     */
    private static function advanceQueue(Closure $audit): ?SongRequest
    {
        return DB::transaction(function () use ($audit) {
            // Lock what changes, so two advances at once (a double-tapped
            // Shortcut, or the hook and a moderator) move the queue one step
            // each instead of both starting the same request.
            $finished = SongRequest::where('status', SongRequestStatus::Playing->value)->lockForUpdate()->first();
            $next = SongRequest::queued()->lockForUpdate()->first();

            self::finishPlaying();
            $next?->update(['status' => SongRequestStatus::Playing, 'started_at' => now()]);

            $audit($next, $finished);

            return $next;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditDetails(?SongRequest $next, ?SongRequest $finished): array
    {
        return array_filter([
            'finished_id' => $finished?->id,
            'playing_id' => $next?->id,
            'track_id' => $next?->track_id,
        ], fn ($value) => $value !== null);
    }

    /**
     * Drop a queued or playing request without counting it as played. A
     * channel-point request skipped before it played is refunded.
     */
    public static function skip(User $moderator, SongRequest $request): void
    {
        Gate::forUser($moderator)->authorize('moderate');

        DB::transaction(function () use ($moderator, $request) {
            // Re-read under a lock, so two moderators skipping at once cannot
            // both see it open and both refund it.
            $current = SongRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($current->status->value, SongRequestStatus::openValues(), true)) {
                throw new InvalidArgumentException('That request has already finished.');
            }
            $wasQueued = $current->status === SongRequestStatus::Queued;

            $current->update(['status' => SongRequestStatus::Skipped, 'finished_at' => now()]);
            ModerationAction::record($moderator, 'song_request.skipped', $current, [
                'track_id' => $current->track_id,
                'requester' => $current->requester_name,
            ]);

            // Skipped before it ever played, so refund the points it cost (#133).
            // Once it is on air, the viewer has had their song.
            if ($wasQueued) {
                self::refundIfPaid($current, 'A moderator skipped the song request before it played.');
            }

            $request->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * Skip every queued request, refunding the channel-point ones. What is
     * playing keeps playing.
     */
    public static function clear(User $moderator): int
    {
        Gate::forUser($moderator)->authorize('moderate');

        return DB::transaction(function () use ($moderator) {
            $queued = SongRequest::where('status', SongRequestStatus::Queued->value)->lockForUpdate()->get();

            $cleared = SongRequest::whereKey($queued->modelKeys())
                ->update(['status' => SongRequestStatus::Skipped->value, 'finished_at' => now()]);
            ModerationAction::record($moderator, 'song_request.cleared', null, ['cleared' => $cleared]);

            // None of them had played, so refund the channel-point ones (#133).
            $queued->each(fn (SongRequest $request) => self::refundIfPaid($request, 'A moderator cleared the song request queue before it played.'));

            return $cleared;
        });
    }

    /**
     * Queue a refund for a channel-point request, once the surrounding
     * transaction commits. Chat requests cost nothing. The refund job is
     * idempotent, so a request can never be refunded twice.
     */
    private static function refundIfPaid(SongRequest $request, string $reason): void
    {
        if ($request->source === SongRequestSource::ChannelPoints && $request->channel_point_redemption_id !== null) {
            RefundChannelPointRedemption::dispatch($request->channel_point_redemption_id, $reason)->afterCommit();
        }
    }

    private static function finishPlaying(): void
    {
        SongRequest::where('status', SongRequestStatus::Playing->value)
            ->update(['status' => SongRequestStatus::Played->value, 'finished_at' => now()]);
    }
}
