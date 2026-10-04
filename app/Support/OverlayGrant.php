<?php

namespace App\Support;

use App\Enums\Overlay;
use App\Models\OverlayToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A short-lived, single-use permission to load one overlay page, handed out
 * in exchange for the token from the URL fragment (#token=…).
 *
 * Browsers never send the fragment to the server, so the token stays out of
 * access logs. The bootstrap page reads it and POSTs it to
 * /overlay/{overlay}/session, which sets this grant and the page reloads.
 *
 * Why a cookie per overlay, not the session: every OBS browser source shares
 * one cookie jar, so all overlays share one Laravel session, and OBS starts
 * them all at once. Concurrent requests rewrite the whole session payload,
 * so one source's grant could overwrite another's. Each grant cookie is
 * separate, scoped by path to its own overlay, encrypted by EncryptCookies,
 * HttpOnly and SameSite=Strict.
 *
 * The grant only gates the page load. Polling components re-check the token
 * hash on every render (PollingOverlay), so the grant can expire within
 * minutes and work once. Its random id is claimed in the cache on first use,
 * so a replayed cookie is refused too. Rotating the token voids any unused
 * grant.
 */
final class OverlayGrant
{
    public static function cookieName(Overlay $overlay): string
    {
        return 'overlay_grant_'.str_replace('-', '_', $overlay->value);
    }

    public static function cookiePath(Overlay $overlay): string
    {
        return '/overlay/'.$overlay->value;
    }

    public static function ttlSeconds(): int
    {
        return max(10, (int) config('are.overlays.grant_seconds', 120));
    }

    public static function issue(Overlay $overlay, Request $request): Cookie
    {
        $payload = json_encode([
            'id' => Str::random(32),
            'overlay' => $overlay->value,
            'hash' => OverlayToken::currentHash($overlay),
            'expires' => now()->addSeconds(self::ttlSeconds())->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return cookie(
            self::cookieName($overlay),
            $payload,
            (int) ceil(self::ttlSeconds() / 60),
            self::cookiePath($overlay),
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    /**
     * Use the request's grant for this overlay, if it has one that is
     * unexpired, unused, and whose token has not been rotated since. A grant
     * works once: its id is claimed atomically in the cache, so replaying the
     * cookie inside its lifetime gets the bootstrap page again.
     */
    public static function consume(Overlay $overlay, Request $request): bool
    {
        $raw = $request->cookie(self::cookieName($overlay));

        if (! is_string($raw)) {
            return false;
        }

        $grant = json_decode($raw, true);

        if (! is_array($grant)
            || ! is_string($grant['id'] ?? null)
            || ($grant['overlay'] ?? null) !== $overlay->value
            || ! is_int($grant['expires'] ?? null)
            || $grant['expires'] < now()->getTimestamp()
            || ! OverlayToken::hashIsCurrent($overlay, is_string($grant['hash'] ?? null) ? $grant['hash'] : null)) {
            return false;
        }

        return Cache::add('overlay-grant-used:'.$grant['id'], true, self::ttlSeconds() + 60);
    }

    /** Expire the grant once it has been used. */
    public static function forget(Overlay $overlay): Cookie
    {
        return cookie()->forget(self::cookieName($overlay), self::cookiePath($overlay));
    }
}
