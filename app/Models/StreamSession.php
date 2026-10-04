<?php

namespace App\Models;

use Database\Factories\StreamSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One broadcast on a channel this app serves, from stream.online to stream.offline.
 *
 * @property string $broadcaster_id
 * @property string $twitch_stream_id
 * @property string $type
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property float|string|null $viewer_samples_avg_viewer_count with withAvg()
 * @property int|null $viewer_samples_max_viewer_count with withMax()
 * @property int|null $viewer_samples_count with withCount()
 * @property int|null $chatters_count with withCount()
 */
class StreamSession extends Model
{
    /** @use HasFactory<StreamSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'broadcaster_id',
        'twitch_stream_id',
        'type',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Sessions that have started and not yet ended.
     *
     * @param  Builder<StreamSession>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * The newest open session, on one broadcaster's channel or, with no id, on any channel.
     */
    public static function current(?string $broadcasterId = null): ?self
    {
        return static::live()
            ->when($broadcasterId !== null, fn (Builder $query) => $query->where('broadcaster_id', $broadcasterId))
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    /**
     * This stream's utm_campaign for short links, e.g. "2026-10-04-stream-40123456789".
     * The date keeps it readable in the attribution report; the Twitch stream
     * id keeps two streams on one day apart.
     */
    public function utmCampaign(): string
    {
        return $this->started_at->format('Y-m-d').'-stream-'.$this->twitch_stream_id;
    }

    /**
     * @return HasMany<StreamViewerSample, $this>
     */
    public function viewerSamples(): HasMany
    {
        return $this->hasMany(StreamViewerSample::class);
    }

    /**
     * @return HasMany<StreamChatter, $this>
     */
    public function chatters(): HasMany
    {
        return $this->hasMany(StreamChatter::class);
    }

    /**
     * A keyed hash of a Twitch user id, for counting unique chatters without
     * storing who they are. Keyed with APP_KEY, because Twitch ids are
     * sequential numbers and a plain hash of one is trivially reversed.
     */
    public static function chatterHash(string $twitchUserId): string
    {
        return hash_hmac('sha256', 'twitch-chatter:'.$twitchUserId, (string) config('app.key'));
    }

    /** Count a chatter once per session. Returns whether they were new. */
    public function recordChatter(string $twitchUserId, ?\DateTimeInterface $at = null): bool
    {
        return StreamChatter::query()->insertOrIgnore([
            'stream_session_id' => $this->id,
            'chatter_hash' => static::chatterHash($twitchUserId),
            'first_seen_at' => $at ?? now(),
        ]) === 1;
    }
}
