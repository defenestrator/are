<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\AgentRequest;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Logs every request to /api/agent and ARE's response (#10), refusals
 * included: it runs first, outside authentication and the kill-switch gate.
 * Bodies are kept as sent, capped in length. Headers are never stored, so
 * neither is the bearer token.
 */
class LogAgentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);

        try {
            $agent = Agent::fromToken();
            $token = $agent?->currentAccessToken();

            AgentRequest::create([
                'agent_id' => $agent?->id,
                'token_id' => $token instanceof PersonalAccessToken ? $token->id : null,
                'method' => $request->method(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'route' => $request->route()?->getName(),
                'status' => $response->getStatusCode(),
                'refused' => self::refusal($response),
                'request' => AgentRequest::clip(self::requestBody($request)),
                'response' => AgentRequest::clip((string) $response->getContent()),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            // The log must never turn an answer into an error.
            report($e);
        }

        return $response;
    }

    private static function requestBody(Request $request): ?string
    {
        $body = $request->isMethod('GET') ? $request->query() : $request->all();

        return $body === [] ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function refusal(Response $response): ?string
    {
        $refused = $response->headers->get('X-Agent-Refused');
        if ($refused !== null) {
            return $refused;
        }

        return match ($response->getStatusCode()) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            429 => 'throttled',
            default => null,
        };
    }
}
