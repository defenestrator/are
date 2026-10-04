<?php

namespace App\Models;

use App\Enums\SongRequestSource;
use App\Enums\SongRequestStatus;
use Database\Factories\SongRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A viewer's request to play a catalogue track on stream. Written only
 * through App\SongRequests.
 *
 * @property SongRequestStatus $status
 * @property SongRequestSource $source
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class SongRequest extends Model
{
    /** @use HasFactory<SongRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'track_id',
        'requester_id',
        'requester_name',
        'source',
        'channel_point_redemption_id',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'queued',
    ];

    protected function casts(): array
    {
        return [
            'status' => SongRequestStatus::class,
            'source' => SongRequestSource::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Queued or playing.
     *
     * @param  Builder<SongRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', SongRequestStatus::openValues());
    }

    /**
     * Waiting to play, oldest first.
     *
     * @param  Builder<SongRequest>  $query
     */
    public function scopeQueued(Builder $query): void
    {
        $query->where('status', SongRequestStatus::Queued->value)->orderBy('id');
    }
}
