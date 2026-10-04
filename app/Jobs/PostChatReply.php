<?php

namespace App\Jobs;

use App\IdentityProvider;
use App\Models\BroadcasterToken;
use App\Twitch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Posts a chat command's reply back to the chat it came from (#89).
 *
 * ChatCommandRegistry dispatches it for every non-empty reply. It never runs
 * inside the EventSub request. In order, it:
 *
 * 1. drops the reply if it is older than chat.replies.max_age_seconds;
 * 2. drops it if the channel has used its chat.replies.per_channel budget;
 * 3. claims the source message's reply in chat_command_runs.reply_sent_at, so
 *    a retried or duplicated job never posts twice;
 * 4. posts it. A 429, a 5xx or a connection failure releases the claim and
 *    throws, so the job retries. Anything else is final, and is logged without
 *    the token.
 *
 * Twitch uses Helix Send Chat Message as the broadcaster, replying to the
 * source message. YouTube is behind chat.replies.youtube, which is off by
 * default because each message costs 50 quota units.
 */
class PostChatReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [2, 5];

    public function __construct(
        public IdentityProvider $provider,
        public string $channelId,
        public string $messageId,
        public string $reply,
        public Carbon $repliedAt,
        public string $chatterName = '',
        public string $chatterInput = '',
    ) {}

    /**
     * Whether a reply repeats text the chatter chose: their display name, or
     * what they typed after the command. Replies post as the broadcaster, so
     * one that did would let a viewer make the channel's own account say
     * anything (#128). Replies are fixed templates plus ARE ids; this is the
     * backstop that holds even if a command forgets.
     *
     * Matching is case-insensitive, on whole words, after removing format
     * characters and collapsing spaces. Text with no letters (an id, "12")
     * cannot carry a message and is not checked, nor are names under 3
     * characters or inputs under 6, which would match ordinary template words.
     */
    public static function echoesChatter(string $reply, string $chatterName, string $chatterInput): bool
    {
        $reply = self::normalise($reply);

        foreach ([[$chatterName, 3], [$chatterInput, 6]] as [$text, $minimum]) {
            $text = self::normalise($text);

            if (mb_strlen($text) >= $minimum
                && preg_match('/\p{L}/u', $text)
                && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($text, '/').'(?![\p{L}\p{N}])/u', $reply)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $text): string
    {
        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /** Whether replies on this platform are posted at all. */
    public static function supports(IdentityProvider $provider): bool
    {
        if (! config('chat.replies.enabled')) {
            return false;
        }

        return match ($provider) {
            IdentityProvider::Twitch => true,
            IdentityProvider::YouTube => (bool) config('chat.replies.youtube'),
            default => false,
        };
    }

    public function handle(): void
    {
        $context = ['provider' => $this->provider->value, 'channel_id' => $this->channelId, 'message_id' => $this->messageId];

        if (! static::supports($this->provider) || trim($this->reply) === '') {
            return;
        }

        if (static::echoesChatter($this->reply, $this->chatterName, $this->chatterInput)) {
            // Never log the reply or the name: they are the attacker's text.
            Log::warning('Refused to post a chat reply that repeats the chatter\'s name or input (#128). Fix the command\'s reply template.', $context);

            return;
        }

        if ($this->repliedAt->diffInSeconds(now(), true) > (int) config('chat.replies.max_age_seconds')) {
            Log::info('Dropped a stale chat reply.', $context);

            return;
        }

        $budgetKey = 'chat-reply:'.$this->provider->value.':'.$this->channelId;
        if (RateLimiter::tooManyAttempts($budgetKey, (int) config('chat.replies.per_channel'))) {
            Log::info('Dropped a chat reply: the channel is at its reply cap.', $context);

            return;
        }

        if (! $this->claim()) {
            return; // already posted (or being posted) by an earlier attempt
        }

        RateLimiter::hit($budgetKey, max(1, (int) config('chat.replies.window_seconds')));

        match ($this->provider) {
            IdentityProvider::Twitch => $this->postToTwitch($context),
            default => $this->unsupported($context),
        };
    }

    /**
     * @param  array<string, string>  $context
     */
    private function postToTwitch(array $context): void
    {
        $scopes = BroadcasterToken::where('broadcaster_id', $this->channelId)->first()?->scopes;

        if (is_array($scopes) && ! in_array('user:write:chat', $scopes, true)) {
            Log::warning('Cannot post chat replies: the broadcaster token lacks user:write:chat. The broadcaster must reconnect at /twitch/broadcaster/connect.', $context);

            return;
        }

        try {
            $response = Twitch::sendChatMessage($this->channelId, $this->reply, $this->messageId);
        } catch (ConnectionException $e) {
            $this->retryLater($context, 'connection failed');
        } catch (RuntimeException $e) {
            // No token, or it could not be refreshed. Retrying will not help.
            Log::warning('Cannot post chat replies for this broadcaster: '.$e->getMessage(), $context);

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->retryLater($context, 'HTTP '.$response->status());
        }

        if ($response->failed()) {
            // Never log the request or response headers: the request carried the broadcaster's token.
            Log::warning('Twitch refused a chat reply.', $context + [
                'status' => $response->status(),
                'error' => (string) $response->json('message', ''),
            ]);

            return;
        }

        if ($response->json('data.0.is_sent') === false) {
            Log::warning('Twitch dropped a chat reply.', $context + [
                'drop_code' => (string) $response->json('data.0.drop_reason.code', ''),
                'drop_message' => (string) $response->json('data.0.drop_reason.message', ''),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $context
     */
    private function unsupported(array $context): void
    {
        // YouTube needs a live chat id and a YouTube OAuth token, which arrive
        // with YouTube chat ingestion (#24). Until then nothing is sent.
        Log::warning('Chat replies are enabled for this platform, but posting to it is not implemented yet.', $context);
    }

    /**
     * @param  array<string, string>  $context
     */
    private function retryLater(array $context, string $reason): never
    {
        $this->releaseClaim();

        Log::warning('Posting a chat reply failed; it will be retried.', $context + ['reason' => $reason]);

        throw new RuntimeException('Posting a chat reply failed ('.$reason.'); retrying.');
    }

    /** Atomically mark this message's reply as sent. True only for the first claim. */
    private function claim(): bool
    {
        return DB::table('chat_command_runs')
            ->where('provider', $this->provider->value)
            ->where('message_id', $this->messageId)
            ->whereNull('reply_sent_at')
            ->update(['reply_sent_at' => now()]) === 1;
    }

    private function releaseClaim(): void
    {
        DB::table('chat_command_runs')
            ->where('provider', $this->provider->value)
            ->where('message_id', $this->messageId)
            ->update(['reply_sent_at' => null]);
    }
}
