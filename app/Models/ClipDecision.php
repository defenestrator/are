<?php

namespace App\Models;

use App\Clips\ClipDecisionKind;
use Database\Factories\ClipDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One moderator decision on a clip (#11). See App\Clips\ClipReview.
 *
 * @property int $stream_marker_id
 * @property int|null $user_id
 * @property ClipDecisionKind $decision
 * @property string|null $title
 * @property string|null $trim_start_seconds
 * @property string|null $trim_end_seconds
 * @property string|null $note
 */
class ClipDecision extends Model
{
    /** @use HasFactory<ClipDecisionFactory> */
    use HasFactory;

    protected $fillable = [
        'stream_marker_id',
        'user_id',
        'decision',
        'title',
        'trim_start_seconds',
        'trim_end_seconds',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ClipDecisionKind::class,
            'trim_start_seconds' => 'decimal:1',
            'trim_end_seconds' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<StreamMarker, $this>
     */
    public function streamMarker(): BelongsTo
    {
        return $this->belongsTo(StreamMarker::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
