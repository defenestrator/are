<?php

namespace App\Chat;

use App\IdentityProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A parsed chat command and who sent it, independent of the platform.
 */
final readonly class ChatCommandInvocation
{
    /**
     * @param  string  $name  The command name as typed, lowercased, without the "!"
     * @param  string  $arguments  Everything after the name, trimmed
     * @param  string  $channelId  The platform's id for the channel the message was sent in
     * @param  string  $chatterId  The platform's id for the sender, as stored on identities
     * @param  User|null  $user  The linked user; null only when the command does not require one
     */
    public function __construct(
        public string $name,
        public string $arguments,
        public IdentityProvider $provider,
        public string $channelId,
        public string $chatterId,
        public string $chatterName,
        public string $messageId,
        public ?User $user,
    ) {}

    /**
     * Whether the chatter is the broadcaster or a moderator of the channel this
     * message arrived on (not of some other channel this app serves). Only
     * Twitch channels have known moderators, so other platforms answer false.
     */
    public function canModerate(): bool
    {
        return $this->user !== null
            && $this->provider === IdentityProvider::Twitch
            && Gate::forUser($this->user)->allows('moderateChannel', $this->channelId);
    }
}
