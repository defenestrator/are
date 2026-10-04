<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBan extends Model
{
    protected $fillable = [
        'user_id',
        'moderator_id',
        'reason',
        'ends_at',
        'lifted_at',
    ];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Not lifted, and either permanent or not yet expired.
     *
     * @param  Builder<UserBan>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->whereNull('lifted_at')
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
