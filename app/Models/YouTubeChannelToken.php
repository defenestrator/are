<?php

namespace App\Models;

use App\YouTube\YouTubeApi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A YouTube channel owner's Google OAuth token, used to post chat replies.
 * See App\YouTube\YouTubeApi::accessTokenFor().
 *
 * @property string $channel_id
 * @property string|null $channel_title
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $expires_at
 * @property list<string> $scopes
 * @property int|null $connected_by
 */
class YouTubeChannelToken extends Model
{
    protected $table = 'youtube_channel_tokens';

    protected $fillable = [
        'channel_id',
        'channel_title',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'connected_by',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function isExpired(): bool
    {
        // Refresh a minute early so a request never goes out with a token about to lapse.
        return $this->expires_at->copy()->subMinute()->isPast();
    }

    public function canPost(): bool
    {
        return in_array(YouTubeApi::POST_SCOPE, $this->scopes, true);
    }
}
