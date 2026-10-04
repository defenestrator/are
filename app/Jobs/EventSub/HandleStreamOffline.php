<?php

namespace App\Jobs\EventSub;

use App\Models\StreamSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * stream.offline: end the stream session that went offline.
 *
 * The event carries no end time, so the session ends at the notification's
 * message timestamp rather than whenever a worker gets to this job.
 *
 * Jobs can run out of order: after a reconnect, Twitch sends offline(A) and
 * then online(B) seconds apart, and B's job may run first. So only a session
 * that had started by the time of this notification can be the one that went
 * offline. A session that the next stream.online already closed, at a time
 * later than this notification, is also a candidate: its end is corrected to
 * the real offline time.
 */
class HandleStreamOffline extends EventSubJob
{
    protected function process(): void
    {
        $endedAt = $this->sentAt();
        $streamId = $this->string('id');

        $candidates = StreamSession::where('broadcaster_id', $this->string('broadcaster_user_id'))
            ->where('started_at', '<=', $endedAt)
            ->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $endedAt));

        // Twitch's payload normally has no stream id. When it does, and we know
        // that stream, only that stream may be ended.
        if ($streamId !== '' && StreamSession::where('twitch_stream_id', $streamId)->exists()) {
            $candidates->where('twitch_stream_id', $streamId);
        }

        $session = $candidates->latest('started_at')->first();

        if ($session === null) {
            logger()->info('stream.offline matched no stream session', [
                'broadcaster_id' => $this->string('broadcaster_user_id'),
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $session->update(['ended_at' => $endedAt]);
    }
}
