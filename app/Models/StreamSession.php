<?php

namespace App\Models;

use Database\Factories\StreamSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One broadcast on a channel this app serves, from stream.online to stream.offline.
 *
 * @property string $broadcaster_id
 * @property string $twitch_stream_id
 * @property string $type
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
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
}
