<?php

namespace App\Models;

use App\Clips\StreamMarkerStatus;
use Database\Factories\StreamMarkerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'status' => StreamMarkerStatus::class,
            'position_seconds' => 'integer',
            'clip_requested_at' => 'datetime',
            'download_urls_expire_at' => 'datetime',
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
