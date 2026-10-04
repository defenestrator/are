<?php

namespace App\Jobs\EventSub;

use App\Models\StreamSession;

/**
 * stream.offline: close the open stream session.
 *
 * The event carries no end time, so the session ends at the notification's
 * message timestamp rather than whenever a worker gets to this job.
 */
class HandleStreamOffline extends EventSubJob
{
    protected function process(): void
    {
        $sessions = StreamSession::live()->where('broadcaster_id', $this->string('broadcaster_user_id'));

        // Match on the stream id when Twitch sends one; otherwise close the latest open session.
        $session = ($this->string('id') === '' ? null : (clone $sessions)->where('twitch_stream_id', $this->string('id'))->first())
            ?? $sessions->latest('started_at')->first();

        if ($session === null) {
            logger()->info('stream.offline with no open stream session', [
                'broadcaster_id' => $this->string('broadcaster_user_id'),
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $session->update(['ended_at' => $this->sentAt()]);
    }
}
