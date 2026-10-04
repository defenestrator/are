<?php

namespace App\Jobs\EventSub;

use App\Data\ChatMessage;
use App\Events\Twitch\ChatMessageReceived;

/**
 * channel.chat.message: normalise the message and hand it on. Parsing
 * commands out of it is #21's job, as a ChatMessageReceived listener.
 */
class HandleChatMessage extends EventSubJob
{
    protected function process(): void
    {
        ChatMessageReceived::dispatch(ChatMessage::fromEvent($this->event, $this->sentAt));
    }
}
