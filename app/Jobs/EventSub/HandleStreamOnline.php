<?php

namespace App\Jobs\EventSub;

use App\Models\StreamSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * stream.online: open a stream session.
 */
class HandleStreamOnline extends EventSubJob
{
    protected function process(): void
    {
        $broadcasterId = $this->string('broadcaster_user_id');
        $startedAt = empty($this->event['started_at']) ? $this->sentAt() : Carbon::parse($this->event['started_at']);

        DB::transaction(function () use ($broadcasterId, $startedAt) {
            // A channel streams once at a time. A session still open from an
            // earlier stream missed its stream.offline; end it where this one starts.
            StreamSession::live()
                ->where('broadcaster_id', $broadcasterId)
                ->where('twitch_stream_id', '!=', $this->string('id'))
                ->where('started_at', '<', $startedAt)
                ->update(['ended_at' => $startedAt]);

            StreamSession::firstOrCreate(
                ['twitch_stream_id' => $this->string('id')],
                [
                    'broadcaster_id' => $broadcasterId,
                    'type' => $this->string('type') ?: 'live',
                    'started_at' => $startedAt,
                ],
            );
        });
    }
}
