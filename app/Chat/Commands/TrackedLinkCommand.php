<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\IdentityProvider;
use App\Models\ShortLink;
use App\Models\StreamSession;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A chat command that answers with a tracked short link, such as !orkestera.
 *
 * The link is ShortLink::for(destination, platform, 'stream', campaign, 'chat'),
 * where the campaign is the current stream (see campaignFor()). The same
 * command on the same platform during the same stream therefore always gets
 * the same /go/ code, so its clicks add up.
 *
 * Anyone may use it, linked or not. Each command answers at most once per
 * cooldown in each channel, however many people ask, so chat cannot make the
 * bot spam links. Inside the cooldown it does nothing, and its reply is empty.
 */
abstract class TrackedLinkCommand implements ChatCommand
{
    /** The key under config('are.chat_links'): copy and destination. */
    abstract protected function key(): string;

    public function names(): array
    {
        return [$this->key()];
    }

    public function requiresUser(): bool
    {
        return false;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        $cooldownKey = 'chat-link:'.$this->key().':'.$invocation->provider->value.':'.$invocation->channelId;

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return new ChatCommandResult(ChatCommandStatus::RateLimited);
        }

        RateLimiter::hit($cooldownKey, max(1, (int) config('are.chat_links.cooldown_seconds')));

        $link = ShortLink::for(
            (string) config('are.chat_links.'.$this->key().'.destination'),
            $invocation->provider->value,
            'stream',
            static::campaignFor($invocation->provider, $invocation->channelId),
            'chat',
        );

        return ChatCommandResult::done(str_replace(
            ':url',
            $link->url(),
            (string) config('are.chat_links.'.$this->key().'.reply'),
        ));
    }

    /**
     * The open stream on this Twitch channel; else any open stream (the show
     * simulcast to another platform); else today's date with "-offline", so
     * links shared outside a stream still group by day.
     */
    public static function campaignFor(IdentityProvider $provider, string $channelId): string
    {
        $session = ($provider === IdentityProvider::Twitch ? StreamSession::current($channelId) : null)
            ?? StreamSession::current();

        return $session?->utmCampaign() ?? now()->format('Y-m-d').'-offline';
    }
}
