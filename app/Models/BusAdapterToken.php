<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bearer token a game adapter uses to poll GET /bus/{game}/actions. The
 * plaintext is shown once by `bus:token`; only its SHA-256 is kept. It is 256
 * random bits, so a fast hash is enough.
 *
 * @property string $game
 * @property string $token_hash
 */
class BusAdapterToken extends Model
{
    protected $fillable = [
        'game',
        'token_hash',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /**
     * Issue a fresh token for the game, replacing any previous one.
     */
    public static function issue(string $game): string
    {
        $token = bin2hex(random_bytes(32));

        self::updateOrCreate(['game' => $game], ['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    public static function verify(string $game, mixed $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        $current = self::where('game', $game)->value('token_hash');

        return is_string($current) && hash_equals($current, hash('sha256', $token));
    }
}
