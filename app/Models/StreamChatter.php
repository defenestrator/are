<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone who chatted during a stream, kept only as a keyed hash of their
 * Twitch user id (see StreamSession::chatterHash()), to count unique chatters.
 *
 * @property int $stream_session_id
 * @property string $chatter_hash
 * @property Carbon $first_seen_at
 */
class StreamChatter extends Model
{
    public $timestamps = false;

    protected $fillable = ['stream_session_id', 'chatter_hash', 'first_seen_at'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<StreamSession, $this>
     */
    public function streamSession(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class);
    }
}
