<?php

namespace App\Models;

use App\ControlBus\BallotStatus;
use App\IdentityProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat action sent to the Chat Control Bus, whatever became of it. The
 * table is the bus's audit log; rows are never deleted.
 *
 * @property int $id
 * @property string|null $game
 * @property int|null $window_id
 * @property int|null $user_id
 * @property int|null $agent_id
 * @property IdentityProvider|null $provider null when an agent cast it
 * @property string|null $verb
 * @property string|null $argument
 * @property string|null $action_key
 * @property int|null $option_number
 * @property BallotStatus $status
 * @property bool $subscriber
 */
class BusBallot extends Model
{
    protected $fillable = [
        'game',
        'window_id',
        'user_id',
        'agent_id',
        'provider',
        'message_id',
        'verb',
        'argument',
        'action_key',
        'option_number',
        'status',
        'subscriber',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
            'status' => BallotStatus::class,
            'subscriber' => 'boolean',
            'option_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BusWindow, $this>
     */
    public function window(): BelongsTo
    {
        return $this->belongsTo(BusWindow::class, 'window_id');
    }
}
