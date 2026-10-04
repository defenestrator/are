<?php

/*
 * Proxies whose X-Forwarded-* headers Laravel's TrustProxies middleware
 * believes. It reads this key when bootstrap/app.php sets no proxies.
 * request()->ip() comes from it, and so do the per-IP rate limits
 * (the lead form, for example).
 *
 * The default (unset) trusts no proxy, so clients cannot spoof their IP
 * with X-Forwarded-For. If the app sits behind a load balancer or CDN, set
 * TRUSTED_PROXIES to its addresses or CIDRs, comma-separated. Otherwise
 * every visitor shares the proxy's IP and one rate-limit bucket. Use "*"
 * only when the origin accepts traffic from the proxy alone.
 */

return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
