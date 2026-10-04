<?php

namespace App\Models;

use App\ControlBus\Mode;
use App\ControlBus\WindowStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One vote window of a game in democracy or weighted-random mode.
 *
 * @property int $id
 * @property string $game
 * @property Mode $mode
 * @property WindowStatus $status
 * @property Carbon $opens_at
 * @property Carbon $closes_at
 * @property Carbon|null $resolved_at
 * @property int $total_votes
 */
class BusWindow extends Model
{
    protected $fillable = [
        'game',
        'mode',
        'status',
        'opens_at',
        'closes_at',
        'resolved_at',
        'total_votes',
    ];

    protected function casts(): array
    {
        return [
            'mode' => Mode::class,
            'status' => WindowStatus::class,
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'resolved_at' => 'datetime',
            'total_votes' => 'integer',
        ];
    }

    /**
     * @param  Builder<BusWindow>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', WindowStatus::Open);
    }

    /**
     * @return HasMany<BusBallot, $this>
     */
    public function ballots(): HasMany
    {
        return $this->hasMany(BusBallot::class, 'window_id');
    }

    public function isDue(): bool
    {
        return $this->status === WindowStatus::Open && $this->closes_at->lte(now());
    }
}
