<?php

namespace App\Models;

use App\TwitchSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserTwitchSubscription extends Model
{
    /** @use HasFactory<\Database\Factories\UserTwitchSubscriptionFactory> */
    use HasFactory;

    /**
     * The table's primary key is (user_id, broadcaster_id). Eloquent cannot
     * model a composite key, and declaring one as an array made every update
     * throw a TypeError, so a viewer whose tier changed could never log in
     * again. Eloquent is given user_id as a nominal key, and every save and
     * delete is scoped to both columns below.
     */
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'user_id',
        'broadcaster_id',
        'twitch_subscription',
    ];

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('user_id', $this->getOriginal('user_id', $this->getAttribute('user_id')))
            ->where('broadcaster_id', $this->getOriginal('broadcaster_id', $this->getAttribute('broadcaster_id')));
    }

    public function casts(): array
    {
        return [
            'twitch_subscription' => TwitchSubscription::class,
        ];
    }
}
