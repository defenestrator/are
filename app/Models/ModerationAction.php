<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of what a moderator did, so actions can be reviewed.
 * moderator_id is null for actions run from the CLI (such as bus:kill), by a
 * local music player (details.player, see recordForPlayer), or by a moderator
 * whose account was since deleted.
 *
 * @property array<string, mixed>|null $details
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
    public static function record(?User $moderator, string $action, ?Model $subject = null, array $details = []): self
    {
        return self::create([
            'moderator_id' => $moderator?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
        ]);
    }

    /**
     * Record an action taken by a local player through its token (#136),
     * rather than by a signed-in moderator. The player's name goes in the
     * details, so the audit log can say who acted.
     *
     * @param  array<string, mixed>  $details
     */
    public static function recordForPlayer(MusicPlayerToken $player, string $action, ?Model $subject = null, array $details = []): self
    {
        return self::create([
            'moderator_id' => null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => ['player' => $player->name] + $details,
        ]);
    }

    /**
     * Who acted, for display: the moderator; a named music player (#141); the
     * CLI, with the command when it was recorded (#149); and only otherwise a
     * moderator whose account was deleted. A CLI action must never read as
     * "deleted user": nobody's account went anywhere.
     */
    public function actorName(): string
    {
        if ($this->moderator !== null) {
            return $this->moderator->name;
        }

        $details = $this->details ?? [];

        if (isset($details['player'])) {
            return 'player '.$details['player'];
        }

        if (($details['via'] ?? null) === 'cli') {
            return isset($details['command']) ? 'CLI ('.$details['command'].')' : 'CLI';
        }

        return 'deleted user';
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
