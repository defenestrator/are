{{--
    Served for /overlay/{overlay} when the request has no grant cookie. It holds
    no overlay data, and nothing request-derived beyond the overlay name and
    layout. The token is never sent here: it sits in the URL fragment, which
    the browser keeps to itself.

    The script reads #token=, POSTs it in the request body to
    /overlay/{overlay}/session for a short-lived grant cookie, then reloads. The
    reload carries the cookie, and EnsureOverlayToken serves the real overlay.
    Messages go to the console, which OBS writes to its log.
--}}
<x-layouts.overlay :overlay="$overlay" :layout="$layout">
    <x-overlay.empty message="Connecting the overlay." />

    <script>
        (() => {
            const overlay = @js($overlay->value);
            const exchangeUrl = @js(route('overlay.session', ['overlay' => $overlay]));
            const reloadsKey = 'are-overlay-reloads:' + overlay;
            const log = (message) => console.warn('[ARE overlay] ' + overlay + ': ' + message);

            const match = /(?:^#|&)token=([^&]*)/.exec(window.location.hash);
            if (!match || match[1] === '') {
                log('No #token= in the URL. Get one with: php artisan overlay:token ' + overlay + ' --rotate');
                return;
            }

            let token;
            try {
                token = decodeURIComponent(match[1]);
            } catch (error) {
                log('The #token= in the URL is malformed.');
                return;
            }

            // If the grant cookie never sticks, don't reload forever.
            const recentReloads = () => {
                try {
                    return JSON.parse(sessionStorage.getItem(reloadsKey) || '[]').filter((t) => Date.now() - t < 60000);
                } catch (error) {
                    return [];
                }
            };
            const remember = (reloads) => {
                try {
                    sessionStorage.setItem(reloadsKey, JSON.stringify([...reloads, Date.now()]));
                } catch (error) {
                    // Without storage there is no loop guard, but the exchange still works.
                }
            };

            const exchange = () => {
                const reloads = recentReloads();
                if (reloads.length >= 3) {
                    log('The grant cookie was not accepted after 3 reloads. Check that this browser keeps cookies.');
                    return;
                }

                fetch(exchangeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({token}),
                }).then((response) => {
                    if (response.status === 204) {
                        remember(reloads);
                        window.location.reload();
                    } else if (response.status === 419) {
                        // A stale session (the exchange is CSRF-exempt, but an
                        // older deploy or a proxy may still answer 419). A reload
                        // picks up the cookie jar's current session. This counts
                        // toward the 3-reload guard above.
                        log('Exchange got HTTP 419. Reloading to retry.');
                        remember(reloads);
                        window.location.reload();
                    } else if (response.status === 429 || response.status >= 500) {
                        log('Exchange got HTTP ' + response.status + '. Retrying in 30s.');
                        setTimeout(exchange, 30000);
                    } else {
                        log('Token refused (HTTP ' + response.status + '). It may have been rotated.');
                    }
                }).catch((error) => {
                    log('Exchange failed (' + error + '). Retrying in 10s.');
                    setTimeout(exchange, 10000);
                });
            };

            exchange();
        })();
    </script>
</x-layouts.overlay>
