<?php

namespace App\Http\Middleware;

use App\Support\RequestMemo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a RequestMemo scope for one web request, and closes it once the
 * response is built, so nothing memoised outlives the request (#173). That
 * includes tests, where one application serves many requests.
 */
class EnableRequestMemo
{
    public function __construct(private RequestMemo $memo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->memo->enable();

        try {
            return $next($request);
        } finally {
            $this->memo->release();
        }
    }
}
