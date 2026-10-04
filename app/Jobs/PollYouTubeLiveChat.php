<?php

namespace App\Jobs;

use App\Chat\ChatCommandRegistry;
use App\IdentityProvider;
use App\Models\YouTubeLiveChat;
use App\YouTube\Quota;
use App\YouTube\YouTubeApi;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Polls one YouTube live chat once, runs any chat commands in it, then queues
 * itself again for the next poll. One chain runs per chat.
 *
 * - The wait is YouTube's pollingIntervalMillis, never below
 *   services.youtube.poll_floor_ms (3 s).
 * - rateLimitExceeded doubles the wait, up to a minute. Other failures back
 *   off exponentially, up to five minutes. quotaExceeded waits for midnight PT.
 * - liveChatEnded, liveChatDisabled, liveChatNotFound, forbidden, and a
 *   response carrying offlineAt end the chat.
 * - Messages published before the operator started the chat are skipped, so
 *   the backlog the first page returns never re-runs old commands.
 * - Each message runs its command at most once, because the registry claims
 *   the YouTube message id. A retry that re-reads a page is therefore safe.
 *
 * ShouldBeUniqueUntilProcessing keeps a second copy from being queued (by
 * the stall watchdog, say) while this one waits; its lock is released when the
 * job starts, so the job can queue its successor. WithoutOverlapping drops a
 * copy that starts while another is running.
 */
class PollYouTubeLiveChat implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public int $uniqueFor = 600;

    private const RATE_LIMIT_MAX_MS = 60_000;

    private const ERROR_MAX_MS = 300_000;

    private const ENDING_REASONS = ['liveChatEnded', 'liveChatDisabled', 'liveChatNotFound', 'forbidden'];

    public function __construct(public int $chatId) {}

    public function uniqueId(): string
    {
        return (string) $this->chatId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('youtube-chat:'.$this->chatId))->dontRelease()->expireAfter(120)];
    }

    public function handle(ChatCommandRegistry $commands): void
    {
        $chat = YouTubeLiveChat::find($this->chatId);
        if ($chat === null || ! $chat->isPolling()) {
            return;
        }

        // Another chain already owns the next poll; this copy is surplus.
        if ($chat->next_poll_at !== null && $chat->next_poll_at->isAfter(now()->addSecond())) {
            return;
        }

        try {
            $response = YouTubeApi::liveChatMessages($chat->live_chat_id, $chat->next_page_token);
        } catch (ConnectionException $e) {
            logger()->warning('YouTube live chat poll could not connect', ['video_id' => $chat->video_id, 'error' => $e->getMessage()]);
            $this->scheduleAfterError($chat);

            return;
        }

        if ($response->failed()) {
            $reason = YouTubeApi::errorReason($response);

            if (in_array($reason, self::ENDING_REASONS, true)) {
                $chat->finish(YouTubeLiveChat::ENDED, $reason);

                return;
            }

            if ($reason === 'rateLimitExceeded') {
                $chat->poll_interval_ms = min(max($chat->poll_interval_ms * 2, $this->floor()), self::RATE_LIMIT_MAX_MS);
                $this->schedule($chat, $chat->poll_interval_ms);

                return;
            }

            if ($reason === 'quotaExceeded') {
                logger()->error('YouTube quota is exhausted; live chat polling resumes at midnight PT', ['video_id' => $chat->video_id]);
                $this->schedule($chat, (int) now()->diffInMilliseconds(Quota::resetsAt()) + 60_000);

                return;
            }

            logger()->warning('YouTube live chat poll failed', ['video_id' => $chat->video_id, 'status' => $response->status(), 'reason' => $reason]);
            $this->scheduleAfterError($chat);

            return;
        }

        foreach ((array) $response->json('items', []) as $item) {
            if (is_array($item)) {
                $this->run($commands, $chat, $item);
            }
        }

        $chat->next_page_token = $response->json('nextPageToken') ?: $chat->next_page_token;
        $chat->consecutive_errors = 0;
        $chat->poll_interval_ms = max((int) $response->json('pollingIntervalMillis', 0), $this->floor());

        if ($response->json('offlineAt')) {
            $chat->save();
            $chat->finish(YouTubeLiveChat::ENDED, 'offline');

            return;
        }

        $this->schedule($chat, $chat->poll_interval_ms);
    }

    /**
     * @param  array<string, mixed>  $item  A liveChatMessage resource
     */
    private function run(ChatCommandRegistry $commands, YouTubeLiveChat $chat, array $item): void
    {
        $snippet = (array) ($item['snippet'] ?? []);
        $author = (array) ($item['authorDetails'] ?? []);

        if (($snippet['type'] ?? null) !== 'textMessageEvent') {
            return;
        }

        $publishedAt = isset($snippet['publishedAt']) ? Carbon::parse($snippet['publishedAt']) : null;
        if ($publishedAt === null || $publishedAt->lt($chat->started_at)) {
            return;
        }

        $commands->run(
            IdentityProvider::YouTube,
            $chat->channel_id,
            (string) ($author['channelId'] ?? $snippet['authorChannelId'] ?? ''),
            (string) ($author['displayName'] ?? ''),
            (string) ($item['id'] ?? ''),
            (string) ($snippet['textMessageDetails']['messageText'] ?? $snippet['displayMessage'] ?? ''),
        );
    }

    private function scheduleAfterError(YouTubeLiveChat $chat): void
    {
        $chat->consecutive_errors++;
        $this->schedule($chat, (int) min($this->floor() * (2 ** min($chat->consecutive_errors, 10)), self::ERROR_MAX_MS));
    }

    private function schedule(YouTubeLiveChat $chat, int $delayMs): void
    {
        // Rounded up to whole seconds: timestamps and the database queue keep
        // whole seconds, and rounding down would poll faster than YouTube asked.
        $chat->next_poll_at = now()->addSeconds((int) ceil($delayMs / 1000));
        $chat->save();

        self::dispatch($chat->id)->delay($chat->next_poll_at);
    }

    private function floor(): int
    {
        return max(1000, (int) config('services.youtube.poll_floor_ms'));
    }
}
