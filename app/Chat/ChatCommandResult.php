<?php

namespace App\Chat;

/**
 * What a command did. $reply is the text to send back to chat; the registry
 * queues App\Jobs\PostChatReply to post it (#89). An empty reply posts nothing.
 */
final readonly class ChatCommandResult
{
    public function __construct(
        public ChatCommandStatus $status,
        public string $reply = '',
    ) {}

    public static function done(string $reply = ''): self
    {
        return new self(ChatCommandStatus::Done, $reply);
    }

    public static function rejected(string $reply): self
    {
        return new self(ChatCommandStatus::Rejected, $reply);
    }
}
