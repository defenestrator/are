<?php

namespace App;

use App\Enums\SongRequestSource;
use App\Enums\SongRequestStatus;
use App\Exceptions\SongRequestRejected;
use App\Models\ChannelPointRedemption;
use App\Models\ModerationAction;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\User;
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
            throw SongRequestRejected::notFound($query);
        }

        $tracks = Track::requestable()->orderBy('title')->orderBy('id')->get();

        $exact = $tracks->filter(fn (Track $t) => mb_strtolower($t->title) === $needle);
        $matches = $exact->isNotEmpty() ? $exact : $tracks->filter(fn (Track $t) => str_contains(mb_strtolower($t->title), $needle));

        return match ($matches->count()) {
            0 => throw SongRequestRejected::notFound($query),
            1 => $matches->first(),
            default => throw SongRequestRejected::ambiguous(
                $matches->take(3)->map(fn (Track $t) => "\"{$t->title}\" (#{$t->id})")->values()->all(),
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
                throw SongRequestRejected::notFound($track->title);
            }

            if (SongRequest::open()->where('track_id', $locked->id)->exists()) {
                throw SongRequestRejected::alreadyQueued($locked->title);
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
     * Drop a queued or playing request without counting it as played.
     */
    public static function skip(User $moderator, SongRequest $request): void
    {
        Gate::forUser($moderator)->authorize('moderate');

        if (! in_array($request->status->value, SongRequestStatus::openValues(), true)) {
            throw new InvalidArgumentException('That request has already finished.');
        }

        DB::transaction(function () use ($moderator, $request) {
            $request->update(['status' => SongRequestStatus::Skipped, 'finished_at' => now()]);
            ModerationAction::record($moderator, 'song_request.skipped', $request, [
                'track_id' => $request->track_id,
                'requester' => $request->requester_name,
            ]);
        });
    }

    /**
     * Skip every queued request. What is playing keeps playing.
     */
    public static function clear(User $moderator): int
    {
        Gate::forUser($moderator)->authorize('moderate');

        return DB::transaction(function () use ($moderator) {
            $cleared = SongRequest::where('status', SongRequestStatus::Queued->value)
                ->update(['status' => SongRequestStatus::Skipped->value, 'finished_at' => now()]);
            ModerationAction::record($moderator, 'song_request.cleared', null, ['cleared' => $cleared]);

            return $cleared;
        });
    }

    private static function finishPlaying(): void
    {
        SongRequest::where('status', SongRequestStatus::Playing->value)
            ->update(['status' => SongRequestStatus::Played->value, 'finished_at' => now()]);
    }
}
