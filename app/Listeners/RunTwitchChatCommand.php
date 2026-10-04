<?php

namespace App\Listeners;

use App\Chat\ChatCommandRegistry;
use App\Events\Twitch\ChatMessageReceived;
use App\IdentityProvider;
use App\Models\User;

/**
 * Runs chat commands from Twitch chat. It already runs inside the queued
 * HandleChatMessage job, so it is not queued again.
 */
class RunTwitchChatCommand
{
    public function __construct(private ChatCommandRegistry $commands) {}

    public function handle(ChatMessageReceived $event): void
    {
        $message = $event->message;

        // In a Shared Chat session, a message sent in another channel we serve
        // also arrives on that channel's own subscription. Run it only there,
        // so that one message cannot run a command twice.
        if ($message->isFromSharedChat() && in_array($message->sourceBroadcasterId, User::getBroadcasterIDs(), true)) {
            return;
        }

        $result = $this->commands->run(
            IdentityProvider::Twitch,
            $message->broadcasterId,
            $message->chatterId,
            $message->chatterName,
            $message->messageId,
            $message->text,
        );

        if ($result !== null) {
            logger()->info('Twitch chat command', [
                'broadcaster_id' => $message->broadcasterId,
                'chatter_id' => $message->chatterId,
                'message_id' => $message->messageId,
                'status' => $result->status->value,
            ]);
        }
    }
}
