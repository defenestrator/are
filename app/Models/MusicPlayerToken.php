<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The access token for one named local player that may advance the song
 * request queue (#136). Same scheme as OverlayToken (#50): 256 random bits,
 * shown once by `music:player-token`, and only the SHA-256 is stored.
 *
 * @property string $name
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 */
class MusicPlayerToken extends Model
{
    /** Player names are short slugs, so they read cleanly in the audit log. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    protected $fillable = [
        'name',
        'token_hash',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Issue a fresh token for the player, replacing any previous one, and
     * return the plaintext. The old token stops working immediately.
     */
    public static function issue(string $name): string
    {
        $token = bin2hex(random_bytes(32));

        self::updateOrCreate(['name' => $name], ['token_hash' => OverlayToken::hash($token), 'last_used_at' => null]);

        return $token;
    }

    /**
     * The player a plaintext bearer token belongs to, or null.
     */
    public static function findByToken(mixed $token): ?self
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $hash = OverlayToken::hash($token);
        $player = self::where('token_hash', $hash)->first();

        return $player !== null && hash_equals($player->token_hash, $hash) ? $player : null;
    }
}
