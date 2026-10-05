<?php

namespace App\Http\Middleware;

use App\Agent\AgentGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The kill-switch gate on every agent request (#10). Reads the switches from
 * the database each time (AgentGate::refusal), so the first request after a
 * kill is refused. Controllers check again right before any effect.
 */
class EnsureAgentMayAct
{
    public function handle(Request $request, Closure $next): Response
    {
        $refusal = AgentGate::refusal();

        if ($refusal !== null) {
            return response()
                ->json(['message' => AgentGate::message($refusal), 'refused' => $refusal], 423)
                ->header('X-Agent-Refused', $refusal);
        }

        return $next($request);
    }
}
