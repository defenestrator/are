<?php

namespace App\Models;

use App\ControlBus\ApprovalStatus;
use App\ControlBus\Mode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A free-text action (Orkestera's task) that chat chose, waiting for a
 * moderator to approve it before it is published. It expires to rejected.
 *
 * @property int $id
 * @property string $game
 * @property Mode $mode
 * @property int|null $window_id
 * @property int|null $ballot_id
 * @property string $verb
 * @property string|null $argument
 * @property string $action_key
 * @property int $votes
 * @property int $total_votes
 * @property string|null $flair
 * @property ApprovalStatus $status
 * @property Carbon $expires_at
 * @property string|null $reason
 * @property int|null $publication_id
 */
class BusApproval extends Model
{
    protected $fillable = [
        'game',
        'mode',
        'window_id',
        'ballot_id',
        'verb',
        'argument',
        'action_key',
        'votes',
        'total_votes',
        'flair',
        'status',
        'expires_at',
        'decided_by_id',
        'decided_at',
        'reason',
        'publication_id',
    ];

    protected function casts(): array
    {
        return [
            'mode' => Mode::class,
            'status' => ApprovalStatus::class,
            'votes' => 'integer',
            'total_votes' => 'integer',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BusApproval>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }

    public function label(): string
    {
        return trim($this->verb.' '.$this->argument);
    }

    /**
     * @return BelongsTo<BusWindow, $this>
     */
    public function window(): BelongsTo
    {
        return $this->belongsTo(BusWindow::class, 'window_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
