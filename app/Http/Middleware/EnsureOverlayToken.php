<?php

namespace App\Http\Middleware;

use App\Enums\Overlay;
use App\Models\OverlayToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /overlay/{overlay}: `?token=` must be that overlay's current token.
 * OBS browser sources cannot send headers, so the token rides in the URL.
 */
class EnsureOverlayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Runs before or after SubstituteBindings depending on priority, so
        // accept the bound enum or the raw route segment.
        $overlay = $request->route('overlay');
        $overlay = $overlay instanceof Overlay ? $overlay : Overlay::tryFrom((string) $overlay);

        abort_if($overlay === null, 404);
        abort_unless(OverlayToken::verify($overlay, $request->query('token')), 403);

        $response = $next($request);

        // The token is in the URL: keep it out of Referer headers, search
        // indexes and shared caches.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
