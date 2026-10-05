<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request to /api/agent and ARE's response, refusals included. Bodies
 * are stored as sent and answered, capped in length; headers (and so the
 * bearer token) are never stored.
 *
 * @property int $id
 * @property int|null $agent_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property string|null $refused
 * @property string|null $request
 * @property string|null $response
 * @property int $duration_ms
 * @property Carbon $created_at
 */
class AgentRequest extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Marks a body cut to the cap. */
    public const TRUNCATED = ' [truncated]';

    /**
     * The most of each body kept, in bytes: AGENT_LOG_BODY_KB (16), at most
     * 63 so it fits a 64 KB text column on any database. At the per-token rate limit over
     * the retention window, 16 KB keeps even a runaway agent's log bounded.
     */
    public static function maxBodyBytes(): int
    {
        return min(63, max(1, (int) config('agent.log_body_kb'))) * 1024;
    }

    protected $fillable = [
        'agent_id',
        'token_id',
        'method',
        'path',
        'route',
        'status',
        'refused',
        'request',
        'response',
        'duration_ms',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * Rows older than agent.log_days go (model:prune, daily).
     *
     * @return Builder<AgentRequest>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(max(1, (int) config('agent.log_days'))));
    }

    public static function clip(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $max = self::maxBodyBytes();

        // mb_strcut never splits a multi-byte character.
        return strlen($body) > $max ? mb_strcut($body, 0, $max - strlen(self::TRUNCATED)).self::TRUNCATED : $body;
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
