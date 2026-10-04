<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Concurrent viewers of a live stream at one moment, from Helix Get Streams.
 *
 * @property int $stream_session_id
 * @property Carbon $sampled_at
 * @property int $viewer_count
 */
class StreamViewerSample extends Model
{
    public $timestamps = false;

    protected $fillable = ['stream_session_id', 'sampled_at', 'viewer_count'];

    protected function casts(): array
    {
        return ['sampled_at' => 'datetime', 'viewer_count' => 'integer'];
    }

    /**
     * @return BelongsTo<StreamSession, $this>
     */
    public function streamSession(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class);
    }
}
