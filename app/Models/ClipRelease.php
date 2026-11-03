<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A post-render agent decision, verified against the stored artifact bytes.
 *
 * @property Carbon|null $verified_at
 */
class ClipRelease extends Model
{
    protected $fillable = [
        'stream_marker_id', 'agent_id', 'token_id', 'request_id', 'request_hash',
        'clip_revision', 'disk', 'file_path', 'artifact_sha256', 'render_signature',
        'policy_revision', 'verdict', 'checks', 'status', 'error', 'verified_at',
    ];

    protected $hidden = ['disk', 'file_path', 'token_id', 'request_hash'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<StreamMarker, $this> */
    public function marker(): BelongsTo
    {
        return $this->belongsTo(StreamMarker::class, 'stream_marker_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
