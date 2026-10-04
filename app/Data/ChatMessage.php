<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A Twitch chat message, reduced to what this app acts on.
 *
 * Built from a channel.chat.message EventSub event. Command parsing (#21)
 * starts from this, by listening for ChatMessageReceived.
 *
 * @see https://dev.twitch.tv/docs/eventsub/eventsub-reference/#channel-chat-message-event
 */
final readonly class ChatMessage
{
    /**
     * @param  list<string>  $badges  Badge set ids, e.g. "moderator", "subscriber", "vip"
     */
    public function __construct(
        public string $messageId,
        public string $broadcasterId,
        public string $chatterId,
        public string $chatterLogin,
        public string $chatterName,
        public string $text,
        public string $messageType,
        public array $badges,
        public int $bits,
        public ?string $replyToMessageId,
        public ?string $rewardId,
        public ?string $sourceBroadcasterId,
        public CarbonImmutable $sentAt,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromEvent(array $event, string $sentAt): self
    {
        return new self(
            messageId: (string) ($event['message_id'] ?? ''),
            broadcasterId: (string) ($event['broadcaster_user_id'] ?? ''),
            chatterId: (string) ($event['chatter_user_id'] ?? ''),
            chatterLogin: (string) ($event['chatter_user_login'] ?? ''),
            chatterName: (string) ($event['chatter_user_name'] ?? ''),
            text: trim((string) ($event['message']['text'] ?? '')),
            messageType: (string) ($event['message_type'] ?? 'text'),
            badges: array_values(array_filter(array_map(
                fn ($badge) => is_array($badge) ? (string) ($badge['set_id'] ?? '') : '',
                (array) ($event['badges'] ?? []),
            ))),
            bits: (int) ($event['cheer']['bits'] ?? 0),
            replyToMessageId: $event['reply']['parent_message_id'] ?? null,
            rewardId: $event['channel_points_custom_reward_id'] ?? null,
            sourceBroadcasterId: $event['source_broadcaster_user_id'] ?? null,
            sentAt: CarbonImmutable::parse($sentAt),
        );
    }

    public function hasBadge(string $setId): bool
    {
        return in_array($setId, $this->badges, true);
    }

    /**
     * True for messages relayed from another channel during a Shared Chat session.
     */
    public function isFromSharedChat(): bool
    {
        return $this->sourceBroadcasterId !== null && $this->sourceBroadcasterId !== $this->broadcasterId;
    }
}
