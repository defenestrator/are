<?php

namespace App\Models;

use App\ControlBus\Mode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An action the bus sent to a game's adapters. Its id is the cursor adapters
 * poll from. A vetoed publication stays, marked, so adapters can undo it.
 *
 * @property int $id
 * @property string $game
 * @property Mode $mode
 * @property int|null $window_id
 * @property string $verb
 * @property string|null $argument
 * @property int $votes
 * @property int $total_votes
 * @property string|null $flair
 * @property Carbon|null $vetoed_at
 * @property Carbon $created_at
 */
class BusPublication extends Model
{
    protected $fillable = [
        'game',
        'mode',
        'window_id',
        'ballot_id',
        'verb',
        'argument',
        'votes',
        'total_votes',
        'flair',
        'vetoed_at',
        'vetoed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'mode' => Mode::class,
            'votes' => 'integer',
            'total_votes' => 'integer',
            'vetoed_at' => 'datetime',
        ];
    }

    /**
     * What adapters receive, over Reverb and from the polling endpoint alike.
     * No user ids or names: only the action and how much of chat backed it.
     *
     * @return array{id: int, game: string, verb: string, argument: string|null, mode: string, votes: int, total_votes: int, window_id: int|null, flair: string|null, vetoed: bool, published_at: string}
     */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'game' => $this->game,
            'verb' => $this->verb,
            'argument' => $this->argument,
            'mode' => $this->mode->value,
            'votes' => $this->votes,
            'total_votes' => $this->total_votes,
            'window_id' => $this->window_id,
            'flair' => $this->flair,
            'vetoed' => $this->vetoed_at !== null,
            'published_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function vetoedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vetoed_by_id');
    }
}
