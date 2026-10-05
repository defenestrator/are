<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an agent's token reaches /api/agent: a user's token (such as a
 * moderator's kill-switch token) gets a 403.
 */
class EnsureAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Agent::fromToken() === null) {
            return response()->json(['message' => 'This endpoint is for agent tokens.'], 403);
        }

        return $next($request);
    }
}
