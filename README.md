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
touch database/database.sqlite && php artisan migrate
composer run dev            # or: php artisan serve + npm run dev
./vendor/bin/pest           # tests
```

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

Every overlay has its own token. `php artisan overlay:token queue` prints the horizontal and vertical URLs once. Only a hash is stored, so the URL can't be shown again. To replace a leaked URL, run `php artisan overlay:token queue --rotate`; the old URL stops working, and any open copy goes blank on its next refresh. The queue, vote and top-vote overlays refresh every 5 seconds.

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
rebuilds caches, restarts queue workers and reloads PHP-FPM. The FPM reload is
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

`/horizon` is open to everyone in `local`. Elsewhere only admins can open it: broadcasters and moderators of a served channel who are not banned (the same `moderate` gate the rest of the app uses). Everyone else gets a 403.

Operator checklist:

1. **Check the server.** Under Server > Overview, confirm it is an **App** server, because only App and Cache servers come with Redis. SSH in and check `redis-cli ping` (expect `PONG`), `php -m | grep -i redis` and `ulimit -n`.
2. **Note the deploy strategy.** If the deploy script contains `$CREATE_RELEASE()`, the site uses zero-downtime deployments.
3. **Optional: set a Redis password** (Server > Settings > Recipes > Redis "Set password"), then set `REDIS_PASSWORD` in the site environment.
4. **Set the site environment:** `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and `REDIS_CLIENT`. Use `phpredis` if step 1 listed the `redis` extension, otherwise `predis`. `APP_ENV` must be `production`, because Horizon only starts the supervisors configured for the current environment.
5. **Turn on the "Laravel Horizon" toggle** on the site's Overview tab. Then delete any plain queue workers for the site, because Forge says not to run them alongside Horizon. Make sure the **Laravel Scheduler** toggle is on: it runs `horizon:snapshot` every five minutes for the metrics, as well as `twitch:sync-moderation`.
6. **Check the deploy script restarts Horizon after the new code is live.** On zero-downtime sites, `$RESTART_QUEUES()` after `$ACTIVATE_RELEASE()` covers Horizon. On standard sites, end with `$FORGE_PHP artisan horizon:terminate`. Forge appends that line if it is missing. Alternatively, `$FORGE_PHP artisan reload` (Laravel 12.45+) runs `horizon:terminate` along with the other reloadable services, such as Reverb once it lands.
7. **Set the Horizon daemon's Stop Seconds** to at least the longest job's runtime (the longest supervisor `timeout`, 60 seconds today), so a deploy doesn't kill a job mid-run.
8. **Smoke test.** `/horizon` should load for an admin and return 403 for a viewer. The dashboard should show both supervisors running. After ten minutes, the Metrics tab should have data.
