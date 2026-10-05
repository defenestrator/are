<?php

namespace App\Models;

use App\Clips\ClipReviewStatus;
use App\Clips\StreamMarkerStatus;
use Database\Factories\StreamMarkerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A !clip from chat: a Helix stream marker and the clip cut around it (#11).
 *
 * @property int $id
 * @property int|null $stream_session_id
 * @property string $broadcaster_id
 * @property string|null $twitch_marker_id
 * @property int|null $position_seconds
 * @property string|null $description
 * @property int|null $created_by_user_id
 * @property StreamMarkerStatus $status
 * @property string|null $error
 * @property string|null $vod_id
 * @property string|null $clip_id
 * @property string|null $clip_edit_url
 * @property Carbon|null $clip_requested_at
 * @property string|null $landscape_download_url
 * @property string|null $portrait_download_url
 * @property Carbon|null $download_urls_expire_at
 * @property Carbon|null $clip_attempted_at
 * @property float|null $clip_duration_seconds
 * @property string|null $landscape_file_path
 * @property string|null $portrait_file_path
 * @property int|null $file_bytes
 * @property Carbon|null $fetched_at
 * @property string|null $fetch_error
 * @property ClipReviewStatus $review_status
 * @property string|null $title
 * @property float|null $trim_start_seconds
 * @property float|null $trim_end_seconds
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $files_pruned_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 */
class StreamMarker extends Model
{
    /** @use HasFactory<StreamMarkerFactory> */
    use HasFactory;

    protected $fillable = [
        'stream_session_id',
        'broadcaster_id',
        'twitch_marker_id',
        'position_seconds',
        'description',
        'created_by_user_id',
        'status',
        'error',
        'vod_id',
        'clip_id',
        'clip_edit_url',
        'clip_requested_at',
        'landscape_download_url',
        'portrait_download_url',
        'download_urls_expire_at',
        'clip_attempted_at',
        'clip_duration_seconds',
        'landscape_file_path',
        'portrait_file_path',
        'file_bytes',
        'fetched_at',
        'fetch_error',
        'review_status',
        'title',
        'trim_start_seconds',
        'trim_end_seconds',
        'reviewed_by_user_id',
        'reviewed_at',
        'files_pruned_at',
        'published_at',
    ];

    /** File variants Get Clips Download offers. */
    public const VARIANTS = ['landscape', 'portrait'];

    protected function casts(): array
    {
        return [
            'status' => StreamMarkerStatus::class,
            'position_seconds' => 'integer',
            'clip_requested_at' => 'datetime',
            'download_urls_expire_at' => 'datetime',
            'clip_attempted_at' => 'datetime',
            'clip_duration_seconds' => 'float',
            'file_bytes' => 'integer',
            'fetched_at' => 'datetime',
            'review_status' => ClipReviewStatus::class,
            'trim_start_seconds' => 'float',
            'trim_end_seconds' => 'float',
            'reviewed_at' => 'datetime',
            'files_pruned_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StreamSession, $this>
     */
    public function streamSession(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return HasMany<ClipDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ClipDecision::class);
    }

    /**
     * The clip's length in seconds: what Get Clips reported, or what was
     * asked for (60 s, or less for a marker in the stream's first minute).
     */
    public function clipDuration(): float
    {
        return $this->clip_duration_seconds ?? (float) min(60, (int) $this->position_seconds + 15);
    }

    /** The stored file's path for a variant, or null. Never send this to a browser. */
    public function filePath(string $variant): ?string
    {
        return match ($variant) {
            'landscape' => $this->landscape_file_path,
            'portrait' => $this->portrait_file_path,
            default => null,
        };
    }

    public function downloadUrl(string $variant): ?string
    {
        return match ($variant) {
            'landscape' => $this->landscape_download_url,
            'portrait' => $this->portrait_download_url,
            default => null,
        };
    }

    /**
     * Clips whose files clips:prune-files may delete now (#143): decided,
     * with files still stored, and past their retention.
     *  - Rejected: clips.keep_rejected_days after the decision.
     *  - Approved and not published: clips.keep_approved_days after approval.
     * A clip still to review is never selected, whatever its age; nor is a
     * published one (the upload slice decides what happens to those).
     *
     * @param  Builder<StreamMarker>  $query
     */
    public function scopePrunableFiles(Builder $query): void
    {
        $query->whereNull('files_pruned_at')
            ->where(fn (Builder $q) => $q->whereNotNull('landscape_file_path')->orWhereNotNull('portrait_file_path'))
            ->whereNotNull('reviewed_at')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $r) => $r
                    ->where('review_status', ClipReviewStatus::Rejected)
                    ->where('reviewed_at', '<=', now()->subDays((int) config('clips.keep_rejected_days'))))
                ->orWhere(fn (Builder $a) => $a
                    ->where('review_status', ClipReviewStatus::Approved)
                    ->whereNull('published_at')
                    ->where('reviewed_at', '<=', now()->subDays((int) config('clips.keep_approved_days')))));
    }

    /** Re-checks scopePrunableFiles on this row, e.g. after locking it. */
    public function filesArePrunable(): bool
    {
        return static::whereKey($this->getKey())->prunableFiles()->exists();
    }

    public function downloadUrlsExpired(): bool
    {
        return $this->download_urls_expire_at !== null && $this->download_urls_expire_at->isPast();
    }

    /** The marker's position as H:MM:SS, or null if Twitch never made it. */
    public function position(): ?string
    {
        if ($this->position_seconds === null) {
            return null;
        }

        $s = $this->position_seconds;

        return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    /**
     * Record a failure: a message for mods, plus Twitch's own words when it gave any.
     */
    public function fail(StreamMarkerStatus $status, string $error): void
    {
        $this->update(['status' => $status, 'error' => mb_substr($error, 0, 1000)]);
    }
}
