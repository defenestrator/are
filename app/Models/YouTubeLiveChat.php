<?php

namespace App\Models;

use App\Jobs\PollYouTubeLiveChat;
use Database\Factories\YouTubeLiveChatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A YouTube live video whose chat ARE reads. See PollYouTubeLiveChat.
 *
 * @property string $video_id
 * @property string $channel_id
 * @property string $live_chat_id
 * @property string|null $title
 * @property string $status
 * @property string|null $next_page_token
 * @property int $poll_interval_ms
 * @property Carbon|null $next_poll_at
 * @property int $consecutive_errors
 * @property int $replies_sent
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 */
class YouTubeLiveChat extends Model
{
    /** @use HasFactory<YouTubeLiveChatFactory> */
    use HasFactory;

    public const POLLING = 'polling';

    public const ENDED = 'ended';

    public const STOPPED = 'stopped';

    /** A polling chat whose next poll is this overdue has lost its job. */
    public const STALL_SECONDS = 60;

    protected $table = 'youtube_live_chats';

    protected $fillable = [
        'video_id',
        'channel_id',
        'live_chat_id',
        'title',
        'status',
        'next_page_token',
        'poll_interval_ms',
        'next_poll_at',
        'consecutive_errors',
        'replies_sent',
        'started_at',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'poll_interval_ms' => 'integer',
            'next_poll_at' => 'datetime',
            'consecutive_errors' => 'integer',
            'replies_sent' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<YouTubeLiveChat>  $query
     */
    public function scopePolling(Builder $query): void
    {
        $query->where('status', self::POLLING);
    }

    public function isPolling(): bool
    {
        return $this->status === self::POLLING;
    }

    public function finish(string $status, string $reason): void
    {
        $this->update([
            'status' => $status,
            'ended_at' => now(),
            'end_reason' => $reason,
            'next_poll_at' => null,
        ]);
    }

    /**
     * Restart polling for chats whose job was lost: a job that failed all its
     * tries, a worker that died, a queue that was flushed. Runs every minute
     * from the scheduler. Each restart is a no-op if the chat's job is in fact
     * still queued, because PollYouTubeLiveChat is unique per chat.
     *
     * @return int The number of chats restarted
     */
    public static function resumeStalled(): int
    {
        $stalled = self::polling()
            ->where(fn (Builder $q) => $q->whereNull('next_poll_at')->orWhere('next_poll_at', '<', now()->subSeconds(self::STALL_SECONDS)))
            ->get();

        foreach ($stalled as $chat) {
            logger()->warning('Restarting a stalled YouTube live chat poll', ['video_id' => $chat->video_id]);
            PollYouTubeLiveChat::dispatch($chat->id);
        }

        return $stalled->count();
    }
}
