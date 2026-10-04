<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'not-banned' => \App\Http\Middleware\EnsureNotBanned::class,
            'overlay.token' => \App\Http\Middleware\EnsureOverlayToken::class,
        ]);

        // Twitch signs EventSub webhooks with HMAC; there is no CSRF token to check.
        // The overlay token exchange carries its own credential (the overlay
        // token in the body), and OBS sources sharing one cookie jar would race
        // each other's session-bound CSRF tokens (#58). ExchangeOverlayTokenRequest
        // checks Sec-Fetch-Site/Origin instead.
        $middleware->validateCsrfTokens(except: ['twitch/eventsub', 'overlay/*/session']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();

