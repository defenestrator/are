<?php

namespace App\Listeners;

use App\Events\Twitch\ChatMessageReceived;
use App\Models\StreamSession;

/**
 * Counts each chatter once per stream session, for unique chatters (#12).
 * It stores a keyed hash of the chatter's id and nothing else: no name, no
 * message text. It already runs inside the queued HandleChatMessage job.
 *
 * Not counted: the broadcaster (ARE's own chat replies are sent as the
 * broadcaster), messages outside a live session, and messages relayed from
 * another channel in a Shared Chat session, because those chatters are not
 * in this channel's viewer count.
 */
class RecordTwitchChatter
{
    public function handle(ChatMessageReceived $event): void
    {
        $message = $event->message;

        if ($message->chatterId === '' || $message->chatterId === $message->broadcasterId || $message->isFromSharedChat()) {
            return;
        }

        StreamSession::current($message->broadcasterId)?->recordChatter($message->chatterId, $message->sentAt);
    }
}
