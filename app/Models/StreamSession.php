<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One broadcast on a channel this app serves, from stream.online to stream.offline.
 *
 * @property string $broadcaster_id
 * @property string $twitch_stream_id
 * @property string $type
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
class StreamSession extends Model
{
    /** @use HasFactory<\Database\Factories\StreamSessionFactory> */
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
}
