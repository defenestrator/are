<?php

namespace App\Models;

use App\Twitch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $broadcaster_id
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $expires_at
 * @property list<string> $scopes
 */
class BroadcasterToken extends Model
{
    protected $fillable = [
        'broadcaster_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
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

    /**
     * The scopes in Twitch::BROADCASTER_SCOPES this connection was not granted:
     * it was made before newer features needed them, and must be reconnected.
     * Readiness and Settings both read this.
     *
     * @return list<string>
     */
    public function missingScopes(): array
    {
        return array_values(array_diff(Twitch::BROADCASTER_SCOPES, (array) $this->scopes));
    }

    public function isExpired(): bool
    {
        // Refresh a minute early so a request never goes out with a token about to lapse.
        return $this->expires_at->subMinute()->isPast();
    }
}
