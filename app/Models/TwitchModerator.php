<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwitchModerator extends Model
{
    protected $fillable = [
        'broadcaster_id',
        'twitch_user_id',
    ];
}
