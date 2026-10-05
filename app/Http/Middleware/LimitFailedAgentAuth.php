<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs first on /api/agent, ahead of the request log (#10). An address that
 * keeps failing authentication (a bad, expired or wrong kind of token) is
 * answered 429 before anything is logged, so nobody on the internet can grow
 * agent_requests by sending junk. Only failures count: a working agent never
 * spends this budget, and its own requests are limited per token.
 */
class LimitFailedAgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'agent-auth-failures:'.$request->ip();
        $max = (int) config('agent.failed_auth_per_minute');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['message' => 'Too many failed attempts.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $response = $next($request);

        if (in_array($response->getStatusCode(), [401, 403], true)) {
            RateLimiter::hit($key, 60);
        }

        return $response;
    }
}
