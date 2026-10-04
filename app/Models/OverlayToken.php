<?php

namespace App\Models;

use App\Enums\Overlay;
use Database\Factories\OverlayTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The access token for one OBS overlay. The plaintext token is shown once, by
 * `overlay:token`, and only its SHA-256 is kept. The token is 256 random bits,
 * so a fast hash is enough; a slow password hash would buy nothing.
 *
 * @property Overlay $overlay
 * @property string $token_hash
 */
class OverlayToken extends Model
{
    /** @use HasFactory<OverlayTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'overlay',
        'token_hash',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'overlay' => Overlay::class,
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a fresh token for the overlay, replacing any previous one, and
     * return the plaintext. The old URL stops working immediately.
     */
    public static function issue(Overlay $overlay): string
    {
        $token = bin2hex(random_bytes(32));

        self::updateOrCreate(
            ['overlay' => $overlay],
            ['token_hash' => self::hash($token)],
        );

        return $token;
    }

    public static function currentHash(Overlay $overlay): ?string
    {
        return self::where('overlay', $overlay)->value('token_hash');
    }

    /**
     * Whether a plaintext token from a request is the overlay's current token.
     */
    public static function verify(Overlay $overlay, mixed $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        return self::hashIsCurrent($overlay, self::hash($token));
    }

    /**
     * Whether a hash captured earlier (by a mounted overlay component) still
     * belongs to the overlay's current token, i.e. it has not been rotated.
     */
    public static function hashIsCurrent(Overlay $overlay, ?string $hash): bool
    {
        $current = self::currentHash($overlay);

        if ($current === null || $hash === null) {
            return false;
        }

        return hash_equals($current, $hash);
    }
}
