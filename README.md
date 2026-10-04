[![Laravel Forge Site Deployment Status](https://img.shields.io/endpoint?url=https%3A%2F%2Fforge.laravel.com%2Fsite-badges%2F47b560ae-a754-4c28-bea0-92362c9a3ec6&style=plastic)](https://forge.laravel.com/jeremy-anderson-okr/bright-viper/2088775)

# Applied Research Equity

The investment we make in ourselves

## What even is this?

This is a Twitch app built with Laravel

### I totally stole it from

https://github.com/ThePrimeagen/topshelf-fm

@ThePrimeagen and @teej_dv and Taylor Otwell built a little twitch app on stream one day, using Laravel, for asking questions of Top Shelf guests, and I'm repurposing to fulfill my own twisted desires at https://twitch.tv/jeremyboise

This project has no affiliation with, nor is it endorsed by Laravel, or anyone else, especially me.

#### Do not use this code if you:

- value your own time
- have any self-respect
- are comfortable speaking on stage
- genuinely enjoy kale
- can change a tire

## Running locally, for fools and legends only

```sh
cp .env.example .env        # then fill in the TWITCH_* values
composer install && npm install
php artisan key:generate
php artisan reverb:install  # fills in the REVERB_* keys for live updates
touch database/database.sqlite && php artisan migrate
composer run dev            # server, queue worker, Reverb, logs and Vite
./vendor/bin/pest           # tests
```

## Realtime (Reverb)

The vote page and the queue, vote and top-vote OBS overlays update over WebSockets instead of polling (overlays: see [OBS overlays](#obs-overlays)). `QuestionSubmitted`, `VoteCast`, `TopicChanged` and `QuestionArchived` broadcast on the public `questions` and `topic` channels with ids, vote totals and the topic text only, never who voted. The browser applies votes from the payload itself (`resources/js/live-queue.js`), so a vote costs the server nothing per viewer. Topic changes are handled in the same script: a jittered refresh, plus a `topic-sync` to the topic component, which the fallback polls also send. No Livewire component declares an `echo:` listener, because Livewire logs "Laravel Echo cannot be found" for each one on every load without Reverb. A test guards against adding one. Removed questions are dropped in the browser, and a new question makes each viewer refresh once after a random 0.5–3 s delay, served from a shared 1-entry cache of the queue. Until Reverb is configured (no `VITE_REVERB_APP_KEY` in the build), or whenever the socket is down, the page falls back to refreshing every 5–10 s, as `wire:poll` did, and stops as soon as the socket connects. Merging this before Reverb is set up is therefore safe. Broadcasts are queued jobs on the **`broadcasts`** queue, so nothing goes live unless a worker processes that queue (`composer run dev` runs one locally with `--queue=broadcasts,default`). While `BROADCAST_CONNECTION` is `log` or `null`, the events queue no broadcast jobs at all (`broadcastWhen()`), so votes do not pile up rows in `jobs`. Production topology is in #26.

On Forge, once:

1. **DNS:** add an `A` record for `ws.<domain>` pointing at the server IP.
2. **SSL:** issue a Let's Encrypt certificate covering both `<domain>` and `ws.<domain>`. Forge pre-fills the Reverb host once Reverb is enabled.
3. **Environment and Reverb toggle:** in the site's Environment panel, set fresh `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` (never reuse local ones), `BROADCAST_CONNECTION=reverb`, `REVERB_HOST=ws.<domain>`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, `REVERB_SERVER_PORT=<toggle port>` and the `VITE_REVERB_*` references from `.env.example`. `REVERB_ALLOWED_ORIGINS` defaults to the `APP_URL` host; set it only if pages on another host need the socket. Then enable the **Laravel Reverb** toggle with hostname `ws.<domain>`, port `8080` (or any free local port matching `REVERB_SERVER_PORT`) and maximum connections of about 2,000, so the event-loop extension is installed. `scripts/forge-deploy.sh` already runs `npm run build` (the `VITE_REVERB_*` values are compiled into the bundle, so `.env` must be right first) and `php artisan reverb:restart`. If Forge appends its own `reverb:restart` to the site script, the second restart is harmless. Then redeploy.
4. **Smoke test:** open `/vote` in two browsers and cast a vote. It should appear in the other browser within 1 s. In DevTools, the WebSocket should connect to `wss://ws.<domain>/app/<key>`.

A queue worker must cover the `broadcasts` queue. Horizon (#20) is the plan; until it ships, a plain Forge queue worker needs `--queue=broadcasts,default`.

## Twitch setup

1. In the Twitch developer console, register **both** callback URLs: `TWITCH_REDIRECT_URL` (viewer login) and `TWITCH_BROADCASTER_REDIRECT_URL` (channel connection).
2. Set `TWITCH_CHANNEL_ID` to the primary channel. List any other channels this app serves in `TWITCH_BROADCASTER_IDS`.
3. Each broadcaster logs in, then visits `/twitch/broadcaster/connect` once. This grants `moderation:read`, `channel:moderate` and `channel:manage:broadcast`, stores an encrypted token, and syncs bans and moderators.
4. Run `php artisan twitch:generate-event-sub-key`, deploy, then run `php artisan twitch:eventsub-subscribe`. Twitch then pushes ban, unban and moderator changes to `/twitch/eventsub`.
5. Run the scheduler (`php artisan schedule:work`, or a Forge scheduler job). `twitch:sync-moderation` runs hourly to catch anything EventSub missed.

| Command | What it does |
|---|---|
| `twitch:title "..." [--broadcaster=ID]` | Sets the stream title through Helix |
| `twitch:sync-moderation` | Pulls bans and moderators for every connected channel |
| `twitch:eventsub-subscribe` | Creates the EventSub webhook subscriptions |
| `twitch:generate-event-sub-key` | Writes `TWITCH_HELIX_EVENTSUB_SECRET` to `.env` |
| `short-link:create /about#work-with-us --campaign=<stream> [--source=twitch] [--medium=stream] [--content=overlay] [--code=ork]` | Creates a UTM-tagged short link served at `/go/{code}`. Enquiries from `/about` record the last link clicked |

Moderators of any served channel get the same admin powers as the broadcaster. Anyone banned or timed out on a served channel can't log in, submit or vote until the ban lifts.
## OBS overlays

Each overlay is a transparent page for an OBS browser source, at `/overlay/{name}` where the name is `queue`, `vote`, `top-vote`, `now-playing`, `captions`, `visualizer` or `cta`. Add `?layout=horizontal` for a 1920×1080 source or `?layout=vertical` for a 1080×1920 one.

Every overlay has its own token. `php artisan overlay:token queue` prints the horizontal and vertical URLs once. Only a hash is stored, so the URL can't be shown again. To replace a leaked URL, run `php artisan overlay:token queue --rotate`; the old URL stops working, and any open copy goes blank on its next refresh.

The queue, vote and top-vote overlays follow the public `questions` channel the same way the vote page does (`resources/js/live-overlay.js`). They write vote totals in place, re-sort and renumber ranked lists, drop removed questions at once, and refresh after a random 0.5–3 s delay when only the server knows what belongs in the slice. Refreshes come from the shared queue cache. **Overlay tokens don't gate the socket:** the channel is public, and its payloads are ids, vote totals and versions only, all of which the overlays already show on stream, never question text or who voted. The tokens gate the page and every server render. While the socket is down (and until production has Reverb), the overlays refresh every 5–10 s instead. While it is up, they still refresh every 60–90 s, so a rotated token blanks an open overlay within about a minute and a half. The vote overlay also re-renders when the topic changes (it alone subscribes to the `topic` channel, through `data-live-topic="on"`). The now-playing overlay follows the song queue, not question events, so it keeps polling every 5 seconds, re-checking its token on each poll.

The `cta` lower-third rotates between the Orkestera and EDOS Professional Services calls to action. Set `ARE_CTA_ORKESTERA_URL` and `ARE_CTA_EDOS_URL`: an item with no URL is not shown. The copy lives in `config/are.php`.

**Upgrading from `/top-vote`.** `/top-vote` used to be public. It now redirects permanently to `/overlay/top-vote`, which needs a token, so an existing `/top-vote` source shows nothing (its request gets a 403). OBS caches the redirect. Replace the source URL with the one printed by `php artisan overlay:token top-vote`.

**How the token travels.** `overlay:token` prints URLs like `/overlay/queue?layout=vertical#token=…`. The token is in the **fragment**, which browsers never send to the server, so it doesn't appear in access logs. When OBS loads the source:

1. The server answers with a small bootstrap page that holds no overlay data.
2. The page reads `#token=` and POSTs it, in the request body, to `/overlay/{name}/session`. That endpoint skips Laravel's CSRF token check: every OBS source shares one cookie jar, so overlays starting together would overwrite each other's session and CSRF token, and all but one would fail. The body token is the credential. A same-origin check (`Sec-Fetch-Site`, or `Origin` against the request or `APP_URL`) refuses cross-site requests, and each overlay gets 30 exchanges a minute per address.
3. The server checks the token and sets a grant cookie (`App\Support\OverlayGrant`). The cookie is encrypted, HttpOnly, SameSite=Strict, scoped to that overlay's path, valid for 2 minutes and works once.
4. The page reloads, and the reload with the grant cookie gets the overlay. Rotating the token voids any unused grant, and an open overlay goes blank on its next refresh as before.

If something goes wrong, the bootstrap page logs a line in OBS's log (**Help → Log Files**) starting `[ARE overlay]`.

**Upgrading old `?token=` URLs.** URLs from before this change put the token in the query string. They **still work for one release**, but each load logs a deprecation warning (without the token) to the Laravel log, and nginx still records the token. To move a source over, run `php artisan overlay:token <name> --rotate` and paste the new `#token=` URL into OBS. Once no warnings appear, set `ARE_OVERLAY_ALLOW_QUERY_TOKEN=false` so a leaked old URL is refused.

**Where overlay tokens can still end up.**

- **The web server's access logs: only from old `?token=` URLs.** Fragment URLs never put the token in a request line. If a log with old URLs leaks, rotate the affected tokens.
- **Sentry never receives them.** `App\Support\SentryScrubber`, set as `before_send`, `before_send_transaction` and `before_breadcrumb` in `config/sentry.php`, replaces every `token=` value and every `token` field with `[Filtered]`. Sentry would otherwise attach the full URL and query string to every event, whatever `SENTRY_SEND_DEFAULT_PII` says, and the exchange's request body when PII is on.
- **OBS stores them** in its scene collection JSON on the streaming machine.
- **Pages never pass them on.** Responses send `Referrer-Policy: no-referrer`, so a token never leaves in a `Referer` header, and `Cache-Control: no-store` keeps it out of caches.

### Visualizer audio in OBS

The visualizer reacts to a **capture device**, chosen with `?audio=`:

| `?audio=` | Listens to |
|---|---|
| *(absent)* | Nothing on `/overlay/visualizer`; the mesh only drifts. On `/visualizer`, the bundled demo track, started by a click. |
| `default` | The system's default input device. |
| `<label>` | The input whose label matches, e.g. `?audio=BlackHole%202ch`. An exact label wins, then a device ID, then the first label containing the text, ignoring case. |
| `file` | The bundled demo track. It's silent on the overlay, so it can't reach the stream mix. |
| `none` | Nothing. |

Add `?gain=` (0.1 to 10, default 1) if the mesh barely moves or is always at full stretch. The captured audio is only measured, never played back, so it can't feed back into the stream.

**Getting show audio to the visualizer.** A browser source can't read OBS's mixer. OBS's "Control audio via OBS" option routes the page's *output* into the mixer; it gives the page no input. So the show audio has to reach the page as an input device:

1. Install a virtual audio device: [BlackHole](https://github.com/ExistentialAudio/BlackHole) on macOS or [VB-CABLE](https://vb-audio.com/Cable/) on Windows.
2. In OBS, go to **Settings → Audio → Advanced → Monitoring Device** and choose the virtual device. In **Edit → Advanced Audio Properties**, set the sources the visualizer should follow (music, mic) to **Monitor and Output**. OBS describes monitoring as "playing the audio of the source back through your monitoring device, configured in Settings" ([Audio Mixer guide](https://obsproject.com/kb/audio-mixer-guide)). Alternatively, send an audio interface's loopback channel to that device, or point `?audio=` straight at the interface.
3. **Launch OBS with `--enable-media-stream`.** Without it, `getUserMedia` is refused and the visualizer logs `audio denied`. On Windows, add it to the shortcut target (`"…\obs64.exe" --enable-media-stream`). On macOS, run `open -a OBS --args --enable-media-stream`; OBS documents `open -a "OBS" --args` as the way to pass launch parameters on macOS ([Launch Parameters](https://obsproject.com/kb/launch-parameters)). The flag lets **every** browser source use your microphone and camera, so only add browser sources you trust. On macOS, OBS also needs **System Settings → Privacy & Security → Microphone**; the flag only skips the browser's prompt, not the operating system's.
4. Add the visualizer source with `&audio=BlackHole` (or your device's label) appended to the URL from `php artisan overlay:token visualizer`. Leave **Control audio via OBS** off: the page plays nothing.
5. Check OBS's log (**Help → Log Files → View Current Log**). It records browser-source console messages, and the visualizer logs a line such as `[ARE visualizer] audio live: Listening to "BlackHole 2ch"`. If it says `no-device`, it lists the labels it can see and retries every 5 seconds. That helps when OBS starts before the virtual device. `denied` means the flag in step 3 is missing. `unsupported` means the page isn't on HTTPS.

Why step 3 is needed, from the source at obs-browser [a162443](https://github.com/obsproject/obs-browser/tree/a1624431ae60cd89560d3d12c8143b1b926b410a):
- OBS's Chromium already allows audio without a click: `browser-app.cpp:164` sets `autoplay-policy=no-user-gesture-required`.
- obs-browser registers no `CefPermissionHandler` (`browser-client.hpp:28-36`). CEF's default for a media request is to deny it unless "the `--enable-media-stream` command-line switch is used to grant all permissions" ([CEF `CefPermissionHandler`](https://cef-builds.spotifycdn.com/docs/122.1/classCefPermissionHandler.html)).
- OBS's own launch-parameter documentation doesn't list this switch. It's a Chromium/CEF switch that browser sources honour, and it's widely used for exactly this ([obs-studio#6329](https://github.com/obsproject/obs-studio/issues/6329), [OBS forum](https://obsproject.com/forum/threads/cant-get-mediastream-permissions-in-browser-source.143858/)).
- I tested the switch's behaviour in headless Chrome with fake capture devices. I haven't yet tested it in OBS on the streaming machine. Report back if a platform needs something different.

## Music and song requests

Moderators manage the original music catalogue at `/music/catalogue`. They upload each track and its optional stems, and set its Content ID status. A track registered with Content ID can't be marked stream-safe until all four channels are allow-listed with the distributor. Stream-safe tracks are listed publicly at `/music` with their attribution text, for other creators to download.

Viewers request stream-safe tracks with `!song <title or number>` in chat. They can also redeem the channel-point reward set in `MUSIC_SONG_REQUEST_REWARD_ID`, and the text they enter is the song. A track that is already queued or playing isn't added again. Each person may have `MUSIC_REQUESTS_PER_USER` songs waiting (2 by default) through `!song`; channel-point requests don't count toward that limit. Moderators play, skip and clear requests at `/music/requests`. The `now-playing` overlay shows the request on air, with its title, artist and attribution.

Create the reward with `php artisan music:create-song-reward [--broadcaster=ID] [--title="Request a song"] [--cost=500] [--prompt=...]`. It needs the viewer to type a song, and the command prints the reward id for `MUSIC_SONG_REQUEST_REWARD_ID`. Each channel has its own rewards, so list one id per channel, separated by commas. Running the command again reuses the existing reward.

A refused song redemption (an unknown, ambiguous or already-queued song, or a banned viewer) is **refunded automatically**. So is a channel-point request that a moderator skips or clears **before it plays**. Once a request is on air or has played, it isn't refunded. A queued job cancels it through Helix [Update Redemption Status](https://dev.twitch.tv/docs/api/reference/#update-redemption-status), which returns the points. This needs the `channel:manage:redemptions` scope, so a broadcaster who connected before it was added must reconnect at `/twitch/broadcaster/connect`. Twitch only lets the client id that created a reward update its redemptions. **A reward created in the Twitch dashboard can't be refunded by ARE**: such refunds are logged as warnings and must be done by hand. Use the artisan command instead.

## Forge deployment and PostgreSQL

Create the database and its owning login before deploying. For bright-viper:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=are
DB_USERNAME=are_app
# Set DB_PASSWORD separately in Forge's Environment editor.
```

Use this Forge deploy script (Forge supplies the PHP, Composer and FPM variables):

```bash
set -euo pipefail
cd /home/forge/appliedresearchequity.com
git pull --ff-only origin "$FORGE_SITE_BRANCH"
bash scripts/forge-deploy.sh
```

The checked-in script installs locked dependencies with `npm ci`, clears the old
configuration file, runs migrations **before** clearing the database cache, then
rebuilds caches, restarts queue workers and Reverb, and reloads PHP-FPM. The FPM reload is
required when `opcache.validate_timestamps=0`; CLI migrations alone do not refresh
the web process's cached PHP classes. A failing step stops deployment.

The forward repair migration fills missing `sessions`, `cache`, `cache_locks`,
`jobs`, `job_batches` and `failed_jobs` tables even when their original migrations
are already recorded. It checks each table independently and leaves existing
tables and rows intact. It does not repair arbitrary missing columns or rebuild
the application's migration ledger. Its rollback deliberately retains these
shared tables, so reverting code cannot destroy sessions or queued work.

For an initial database, or recovery from a deploy that stopped before migrations:

```bash
php8.3 artisan config:clear
php8.3 artisan migrate --force
php8.3 artisan cache:clear
```

Do not run `migrate:fresh` or `migrate:reset` in production. Creating the schema
does not transfer users, questions or votes from a previous database. The tests
cover the full migration chain, runtime-table repairs and voting on SQLite and
PostgreSQL 14, including a clean-database deployment preparation and retry.

## Production queues (Forge)

Production runs its queues on Redis under [Horizon](https://laravel.com/docs/12.x/horizon). Local development and tests keep `QUEUE_CONNECTION=database` and `sync`, so you don't need Redis on your machine. Horizon runs two supervisors (`config/horizon.php`):

- `supervisor-broadcasts` works the `broadcasts` queue and always keeps at least one worker, so vote updates never wait behind EventSub jobs.
- `supervisor-default` works the `default` queue.

Every supervisor `timeout` stays below the redis connection's `retry_after` (`REDIS_QUEUE_RETRY_AFTER`, 90 seconds by default). A test enforces this.

**The production Redis is shared** with other apps on this server (gemreptiles, 12thfret), which use the low database indexes. ARE keeps to its own indexes and key prefixes, whatever `APP_NAME` is:

| What | Redis connection | Index | Key prefix |
|---|---|---|---|
| Queue, Horizon, cache locks | `default` | `REDIS_DB`, 4 by default | `are_database_`, and Horizon's own `are_horizon:` |
| Cache | `cache` | `REDIS_CACHE_DB`, 5 by default | `are_database_` + `are_cache_` |

`scripts/forge-deploy.sh` runs `cache:clear`. With `CACHE_STORE=redis` that is a `FLUSHDB` on `REDIS_CACHE_DB` only. It never touches `REDIS_DB` or another app's index, and a test pins this. So `REDIS_CACHE_DB` must be an index nothing else uses: never `REDIS_DB`, and never 0 or 1.

`/horizon` is open to everyone in `local`. Elsewhere only the broadcasters of served channels (`TWITCH_CHANNEL_ID` and `TWITCH_BROADCASTER_IDS`) who are not banned can open it. Everyone else gets a 403, including moderators. The dashboard shows every job's payload and can retry or delete failed jobs, so it is an operator tool, not a moderation one.

Operator checklist:

1. **Check the server.** Under Server > Overview, confirm it is an **App** server, because only App and Cache servers come with Redis. SSH in and check `redis-cli ping` (expect `PONG`), `php -m | grep -i redis` and `ulimit -n`.
2. **Note the deploy strategy.** If the deploy script contains `$CREATE_RELEASE()`, the site uses zero-downtime deployments.
3. **Redis password and indexes.** Set `REDIS_PASSWORD` in the site environment to the server's real Redis password. Run `redis-cli -a "$REDIS_PASSWORD" INFO keyspace` and confirm `db4` and `db5` are absent (empty). If another app already uses them, pick two free indexes and set `REDIS_DB` and `REDIS_CACHE_DB` to them.
4. **Set the site environment:** `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_DB=4`, `REDIS_CACHE_DB=5` (or the indexes from step 3) and `REDIS_CLIENT`. Use `phpredis` if step 1 listed the `redis` extension, otherwise `predis`. Set `APP_ENV=production` to get the production worker counts. Any other non-`local` value (a staging site, say) falls back to the smaller `*` supervisors in `config/horizon.php`.
5. **Turn on the "Laravel Horizon" toggle** on the site's Overview tab. Then delete any plain queue workers for the site, because Forge says not to run them alongside Horizon. Make sure the **Laravel Scheduler** toggle is on: it runs `horizon:snapshot` every five minutes for the metrics, as well as `twitch:sync-moderation`. The snapshot is skipped until `QUEUE_CONNECTION=redis`, so merging before Redis is ready doesn't log a Redis error every five minutes.
6. **Check the deploy script restarts Horizon after the new code is live.** The Forge script above ends with `bash scripts/forge-deploy.sh`, whose `queue:restart` does not restart the Horizon master. When the Horizon toggle is on, Forge appends `$FORGE_PHP artisan horizon:terminate` to the Forge script if it is missing. Keep that line **after** `bash scripts/forge-deploy.sh`, so Horizon restarts on the new code. On a zero-downtime site, `$RESTART_QUEUES()` after `$ACTIVATE_RELEASE()` covers it instead. Alternatively, `$FORGE_PHP artisan reload` (Laravel 12.45+) runs `horizon:terminate` along with the other reloadable services, such as Reverb once it lands.
7. **Set the Horizon daemon's Stop Seconds** to at least the longest job's runtime (the longest supervisor `timeout`, 60 seconds today), so a deploy doesn't kill a job mid-run.
8. **Smoke test.** `/horizon` should load for the broadcaster and return 403 for a moderator and a viewer. The dashboard should show both supervisors running. After ten minutes, the Metrics tab should have data.
