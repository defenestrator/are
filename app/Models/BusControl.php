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
     *
     * Reads first: the rows exist after their first use, so normally this
     * is one SELECT. createOrFirst() would try the INSERT first, which fails by
     * design on every call and writes an ERROR to the Postgres log (#181).
     */
    public static function for(string $scope): self
    {
        $row = self::find($scope);
        if ($row === null) {
            self::ensure([$scope]);
            $row = self::findOrFail($scope);
        }

        return $row;
    }

    /**
     * Create any of these rows that are missing, in one statement that never
     * fails on a duplicate (ON CONFLICT DO NOTHING on Postgres): no error, no
     * savepoint, and safe inside a transaction holding the bus locks. Rows
     * that exist are left exactly as they are, and are not locked by it.
     *
     * @param  list<string>  $scopes
     */
    public static function ensure(array $scopes): void
    {
        $now = now();
        self::query()->insertOrIgnore(array_map(
            fn (string $scope) => ['scope' => $scope, 'created_at' => $now, 'updated_at' => $now],
            array_values(array_unique($scopes)),
        ));
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
