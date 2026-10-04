<?php

namespace App\Http\Middleware;

use App\Enums\Overlay;
use App\Enums\OverlayLayout;
use App\Models\OverlayToken;
use App\Support\OverlayGrant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /overlay/{overlay}. A request gets the overlay when it carries:
 *
 * 1. a grant cookie from OverlayGrant, set by trading the #token= fragment
 *    at /overlay/{overlay}/session. The grant is consumed here; or
 * 2. a legacy ?token= (deprecated, see config('are.overlays.allow_query_token')).
 *
 * Anything else gets the bootstrap page, which holds no overlay data. It reads
 * the token from the fragment, makes the exchange and reloads. A wrong
 * ?token= is still a 403.
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

        if ($request->query->has('token')) {
            abort_unless(config('are.overlays.allow_query_token'), 403);
            abort_unless(OverlayToken::verify($overlay, $request->query('token')), 403);

            // Never log the token itself: that is exactly what this is retiring.
            Log::warning('Overlay token passed in the query string. This is deprecated and will stop working in the next release.', [
                'overlay' => $overlay->value,
                'fix' => "php artisan overlay:token {$overlay->value} --rotate, then use the #token= URL it prints",
            ]);

            return $this->private($next($request));
        }

        if (OverlayGrant::consume($overlay, $request)) {
            $response = $this->private($next($request));
            $response->headers->setCookie(OverlayGrant::forget($overlay));

            return $response;
        }

        return $this->private(response()->view('overlays.bootstrap', [
            'overlay' => $overlay,
            'layout' => OverlayLayout::fromQuery($request->query('layout')),
        ]));
    }

    /**
     * Keep overlay pages out of Referer headers, search indexes and shared caches.
     */
    private function private(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
