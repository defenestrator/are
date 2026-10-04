<?php

namespace App\Models;

use App\ControlBus\Mode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Moderator controls for the Chat Control Bus. The row with scope '*' holds
 * the kill switch and the running game; a row per game holds its pause and
 * mode. Read fresh on every publish: never cache these.
 *
 * @property string $scope
 * @property string|null $active_game
 * @property Mode|null $mode
 * @property Carbon|null $paused_at
 * @property Carbon|null $killed_at
 * @property int|null $replay_floor
 */
class BusControl extends Model
{
    public const GLOBAL = '*';

    protected $primaryKey = 'scope';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scope',
        'active_game',
        'mode',
        'paused_at',
        'paused_by_id',
        'killed_at',
        'killed_by_id',
        'replay_floor',
    ];

    protected function casts(): array
    {
        return [
            'mode' => Mode::class,
            'paused_at' => 'datetime',
            'killed_at' => 'datetime',
            'replay_floor' => 'integer',
        ];
    }

    /**
     * The row for a scope, created if missing. Safe when two requests race.
     */
    public static function for(string $scope): self
    {
        return self::createOrFirst(['scope' => $scope]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function killedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'killed_by_id');
    }
}
