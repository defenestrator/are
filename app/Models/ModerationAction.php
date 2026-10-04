<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of what a moderator did, so actions can be reviewed.
 */
class ModerationAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'moderator_id',
        'action',
        'subject_type',
        'subject_id',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(User $moderator, string $action, ?Model $subject = null, array $details = []): self
    {
        return self::create([
            'moderator_id' => $moderator->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
