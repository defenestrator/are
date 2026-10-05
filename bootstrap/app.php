<?php

use App\Http\Controllers\Testing\LoginAsController;
use App\Http\Middleware\EnableRequestMemo;
use App\Http\Middleware\EnsureAgent;
use App\Http\Middleware\EnsureAgentMayAct;
use App\Http\Middleware\EnsureNotBanned;
use App\Http\Middleware\EnsureOverlayToken;
use App\Http\Middleware\LimitFailedAgentAuth;
use App\Http\Middleware\LogAgentRequest;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Test-only routes for the browser suite (#155), never outside testing.
        then: function () {
            if (LoginAsController::enabled()) {
                Route::middleware('web')->group(__DIR__.'/../routes/testing.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'not-banned' => EnsureNotBanned::class,
            'overlay.token' => EnsureOverlayToken::class,
            'agent.ip' => LimitFailedAgentAuth::class,
            'agent.log' => LogAgentRequest::class,
            'agent.only' => EnsureAgent::class,
            'agent.gate' => EnsureAgentMayAct::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // The agent API (#10): the failed-auth limiter runs first, so junk
        // is refused before it is logged; then the request log wraps
        // authentication, so refused requests are logged too.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: LogAgentRequest::class,
        );
        $middleware->prependToPriorityList(
            before: LogAgentRequest::class,
            prepend: LimitFailedAgentAuth::class,
        );

        // Memoise ban standing and the topic for one request (#173).
        $middleware->web(append: [EnableRequestMemo::class]);

        // Twitch signs EventSub webhooks with HMAC; there is no CSRF token to check.
        // The overlay token exchange carries its own credential (the overlay
        // token in the body), and OBS sources sharing one cookie jar would race
        // each other's session-bound CSRF tokens (#58). ExchangeOverlayTokenRequest
        // checks Sec-Fetch-Site/Origin instead.
        $middleware->validateCsrfTokens(except: ['twitch/eventsub', 'overlay/*/session']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        // The agent API (#10) answers in JSON whatever the client accepts.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
