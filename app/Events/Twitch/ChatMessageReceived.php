<?php

namespace App\Events\Twitch;

use App\Data\ChatMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat message arrived on a channel this app serves. The hand-off point for
 * chat command parsing (#21): listen for this event.
 */
class ChatMessageReceived
{
    use Dispatchable;

    public function __construct(public ChatMessage $message) {}
}
