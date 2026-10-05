<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A question an agent claimed, why it claimed it, and its answer with the
 * moderation verdict from the Orkestera workflow. Moderators read these on
 * /agent; answers marked allowed are the ones fit for clips and analytics.
 *
 * @property int $id
 * @property int $agent_id
 * @property int|null $question_id
 * @property string $question_text
 * @property string $reason
 * @property Carbon $claimed_at
 * @property string|null $answer
 * @property string|null $moderation_verdict
 * @property array<string, mixed>|null $moderation
 * @property Carbon|null $answered_at
 */
class AgentClaim extends Model
{
    public const VERDICTS = ['allowed', 'flagged', 'blocked'];

    protected $fillable = [
        'agent_id',
        'question_id',
        'question_text',
        'reason',
        'claimed_at',
        'answer',
        'moderation_verdict',
        'moderation',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'answered_at' => 'datetime',
            'moderation' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'question' => $this->question_text,
            'reason' => $this->reason,
            'claimed_at' => $this->claimed_at->toIso8601ZuluString(),
            'answer' => $this->answer,
            'moderation_verdict' => $this->moderation_verdict,
            'answered_at' => $this->answered_at?->toIso8601ZuluString(),
        ];
    }
}
