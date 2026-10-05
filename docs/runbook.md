# ARE show-day runbook

This runbook holds every operator action that ARE's merged pull requests asked for, in the order you do them. It has four parts: one-time setup, the show-day preflight, the show itself, and incidents. The [README](../README.md) explains how each feature works; this file says what to do.

**The readiness page shows the state; this runbook gives the procedure.** Open `/admin/readiness` signed in as a broadcaster (#139; its checks live in [`app/Readiness/ReadinessChecks.php`](../app/Readiness/ReadinessChecks.php)). Each red or amber line names its fix. On show day everything must be green before you go live.

Conventions:

- Run every `php artisan …` command on the server, as the `forge` user, in the site directory (`/home/forge/appliedresearchequity.com`). If `php` there is not PHP 8.3, write `php8.3` instead. That's what [`scripts/forge-deploy.sh`](../scripts/forge-deploy.sh) uses.
- After changing any environment variable in Forge, run `php artisan optimize` so the cached config picks it up. The next deploy also does this.
- `docs/runbook.md` is checked by `tests/Feature/RunbookTest.php`. Every `php artisan` command and option, every environment variable and every path in it must exist on `main`. If you add a step, the test tells you when you've named something that doesn't exist.

---

## 1. One-time setup

Do these in order. Later steps assume the earlier ones are done.

### 1.1 Forge site and deploy script

1. **Deploy script** (#65, #60). Replace the site's Forge deployment script with:

   ```bash
   set -euo pipefail
   cd /home/forge/appliedresearchequity.com
   git pull --ff-only origin "$FORGE_SITE_BRANCH"
   bash scripts/forge-deploy.sh
   ```

   `scripts/forge-deploy.sh` runs `npm ci` (never `npm install`, which rewrites the lockfile), `config:clear`, `migrate --force` (before `cache:clear`, which needs the database), `optimize`, `queue:restart` and `reverb:restart`. It then reloads PHP-FPM, because production disables opcode timestamp checks.
2. **Horizon restart** (#40). When the Laravel Horizon toggle is on, keep Forge's appended `$FORGE_PHP artisan horizon:terminate` **after** `bash scripts/forge-deploy.sh`, because `queue:restart` does not restart the Horizon master. `php artisan reload` is an alternative that also covers Reverb.
3. **Never run the test suite on the production checkout** (#86). Run `pest` in dev or CI only.
4. **How code arrives.** The fork's `main` passes CI, `deploy-sync` fast-forwards `defenestrator/are`, and Forge deploys from there (see [CONTRIBUTING.md](../CONTRIBUTING.md)). Nobody pushes to `defenestrator/are` by hand.

### 1.2 Database (PostgreSQL)

Production runs PostgreSQL 14 (#61, #62, #63).

- `.env` in Forge: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=are`, `DB_USERNAME=are_app`, and `DB_PASSWORD` set in Forge's Environment editor.
- The move from SQLite was verified on 2026-10-04 (#64, #66), and the one-off importer has been removed. Keep the old SQLite file and the pre-cutover backup offline until normal backup retention expires. Nothing at runtime reads them.
- **Back up the database before any deploy whose PR says a migration drops columns** (#45 did). Never run `migrate:fresh` or `migrate:reset` in production.

### 1.3 Queues and the scheduler

A queue worker and the scheduler must both run. Without them, chat commands, chat replies, EventSub jobs, lead mail, bus windows, clips, analytics and refunds all wait.

- **Recommended: Redis and Horizon** (#40, see the README's "Production queues (Forge)" for the full checklist):
  1. Check the server: `redis-cli ping` answers `PONG`, and `php -m | grep -i redis` lists the extension.
  2. Set `REDIS_PASSWORD` to the server's real Redis password. Check `redis-cli -a "$REDIS_PASSWORD" INFO keyspace` and confirm `db4` and `db5` are unused. The Redis is shared with other apps on the low indexes.
  3. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_DB=4`, `REDIS_CACHE_DB=5` (never equal to `REDIS_DB`), `REDIS_CLIENT=phpredis` (or `predis`) and `APP_ENV=production`.
  4. Turn on the **Laravel Horizon** toggle, delete any plain queue workers for the site, and set the Horizon daemon's Stop Seconds to at least 60.
  5. Check that `/horizon` loads for a broadcaster and returns 403 for everyone else, with all three supervisors running: `broadcasts`, `default` and `clips` (Shorts cuts, #146, on its own `redis-long` connection).
- **Or database workers**, while `QUEUE_CONNECTION=database`: add a Forge daemon `php artisan queue:work database --queue=broadcasts,default`. It must cover **both** queues, because vote updates and bus windows run on `broadcasts`.
  - Add a **second, separate** Forge daemon for Shorts cuts (#146): `nice -n 10 php artisan queue:work database-long --queue=clips --timeout=600`. Never add `clips` to the worker above: a 5-minute ffmpeg encode there freezes vote updates for 5 minutes. It uses the `database-long` connection, whose `retry_after` (660 s, `DB_LONG_QUEUE_RETRY_AFTER`) is above the job's timeout, so a slow encode is never handed to a second worker. Set the daemon's Stop Seconds to at least 600. The readiness page's **Shorts cut queue** line shows which lane is in use.
- **Scheduler:** turn on the Forge **Laravel Scheduler** toggle (`schedule:run` every minute). The readiness page's Scheduler line turns green a minute later. Scheduled times use `config/app.php`'s timezone, which is UTC: `clips:prune-files` at 04:30, YouTube Analytics at 06:00, and the weekly summary on Mondays at 09:00.

### 1.4 Realtime (Reverb)

Reverb is optional: without it, the vote page and overlays poll every 5 to 10 s (#48, #115, #137). To turn it on:

1. DNS: add an `A` record for `ws.<domain>` pointing at the server.
2. TLS: issue a certificate covering both `<domain>` and `ws.<domain>`.
3. Environment: fresh `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` (never reuse local ones), `BROADCAST_CONNECTION=reverb`, `REVERB_HOST=ws.<domain>`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, `REVERB_SERVER_PORT=<the toggle's port>`, and the `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` and `VITE_REVERB_SCHEME` references from `.env.example`.
4. Turn on the **Laravel Reverb** toggle (hostname `ws.<domain>`, about 2,000 maximum connections), then **redeploy**. The `VITE_REVERB_*` values are compiled into the bundle by `npm run build`, so the build must run after `.env` is right.
5. Smoke test: open `/vote` in two browsers and vote. The vote should appear in the other browser within a second.

While `BROADCAST_CONNECTION` is `log`, nothing is queued for broadcasting (#109).

### 1.5 Upload limits and storage

- **Music uploads** (#43): the app accepts files up to `MUSIC_MAX_UPLOAD_KB` (200 MB). PHP and nginx must allow as much:
  - PHP (Forge: Server → PHP → Max File Upload Size, or `php.ini` for FPM and CLI): `upload_max_filesize = 200M`, `post_max_size = 210M`.
  - nginx (the site's config): `client_max_body_size 210M;`
  - Restart PHP-FPM and reload nginx. If `MUSIC_MAX_UPLOAD_KB` changes, change all three to match.
- **Private disks:** `MUSIC_DISK` and `CLIPS_DISK` default to `local` (`storage/app/private`). Never point either at a public disk. Files are only served through `/music/{track}/download` and `/clips/{marker}/{variant}.mp4`.
- **Clip disk space** (#142, #145): clip MP4s are tens of MB each. `clips:prune-files` deletes rejected and unpublished clips past `CLIPS_KEEP_REJECTED_DAYS` and `CLIPS_KEEP_APPROVED_DAYS`. Preview the first run with `php artisan clips:prune-files --dry-run`.
- **ffmpeg is not needed yet.** Nothing on `main` calls it. Shorts formatting (#146) will, and this step goes here when it merges.

### 1.6 Mail and leads

- Configure a real mailer (#70): `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. The `.env.example` default `MAIL_MAILER=log` only writes mail to the log.
- `ARE_LEADS_NOTIFY`: the inboxes, comma-separated, that get `/about` enquiries. Optionally set `ARE_LEADS_WEBHOOK_URL` (Slack or Discord) and `ARE_WEEKLY_SUMMARY_WEBHOOK_URL` (#108).
- `TRUSTED_PROXIES` (#30): set only if a load balancer or CDN sits in front of the site, so per-IP limits see real visitors.
- Test the weekly summary once with `php artisan attribution:weekly-summary`.
- The `are_attribution` cookie (#97) holds the UTM values of the last ARE short link clicked. If EDOS has a cookie policy, list it there.

### 1.7 Twitch

1. **App credentials:** `TWITCH_CLIENT_ID` and `TWITCH_CLIENT_SECRET`. Register **both** callbacks in the Twitch developer console: `TWITCH_REDIRECT_URL` (`${APP_URL}/twitch/auth`) and `TWITCH_BROADCASTER_REDIRECT_URL` (`${APP_URL}/twitch/broadcaster/callback`).
2. **Channels:** set `TWITCH_CHANNEL_ID` to the primary channel. Other served channels are committed in `config/services.php` (`twitch.broadcaster_ids`); add one by PR. `TWITCH_BROADCASTER_IDS` can add more, comma-separated. A served channel's broadcaster gets every broadcaster permission: Horizon, readiness, leads, bus restore and banning moderators. `TWITCH_FRIEND_IDS` lists channels whose subscribers also count.
3. **Connect every broadcaster** (#37, #93, #95, #129). Each one signs in to ARE, then opens `/twitch/broadcaster/connect`. ARE asks for every scope in `Twitch::BROADCASTER_SCOPES`: `moderation:read`, `channel:moderate`, `channel:manage:broadcast`, `channel:manage:clips`, `user:read:chat`, `user:bot`, `channel:bot`, `user:write:chat`, `channel:read:redemptions`, `channel:manage:redemptions`, `channel:read:subscriptions` and `moderator:read:followers`. **Reconnect after any PR that adds a scope.** The readiness page lists missing scopes per channel.
4. **EventSub:** run `php artisan twitch:generate-event-sub-key` once (it writes `TWITCH_HELIX_EVENTSUB_SECRET`), deploy, then run `php artisan twitch:eventsub-subscribe`. Re-run it after every reconnect and every PR that adds a subscription type (#92). Existing subscriptions return 409, which counts as success. A 403 means a scope is missing. `TWITCH_EVENTSUB_CALLBACK_URL` must be public HTTPS on port 443 (it defaults to `${APP_URL}/twitch/eventsub`).
5. **"Store past broadcasts"** (#95): in the Twitch Creator Dashboard, turn it on (Settings → Stream → VOD Settings) for every served channel. Without it, `!clip` markers fail with a 404.
6. **Song-request reward** (#107, #129). Custom rewards need an Affiliate or Partner channel. For each channel, run:

   ```sh
   php artisan music:create-song-reward
   php artisan music:create-song-reward --broadcaster=<twitch user id> --cost=500
   ```

   The first line is for the primary channel. Put the printed ids, comma-separated, in `MUSIC_SONG_REQUEST_REWARD_ID`, then run `php artisan optimize`. **Don't use a reward made in the Twitch dashboard:** Twitch only lets the client id that created a reward refund its redemptions, so ARE can't refund it. Disable or delete any old dashboard reward.
7. **Moderators** are the Twitch moderators of the served channels. EventSub keeps them current, and `twitch:sync-moderation` runs hourly. To force a refresh, run `php artisan twitch:sync-moderation`.

### 1.8 Google and YouTube

1. **API key** (#91): a key restricted to YouTube Data API v3 and the server's IP, in `YOUTUBE_API_KEY`. Set `YOUTUBE_CHANNEL_IDS` to the channels' `UC…` ids, comma-separated. **Both are required:** with the key set and no channel ids, no YouTube chat is read (#151), and the readiness page shows it in red.
2. **OAuth client** (#126): in the same Google Cloud project, create an OAuth 2.0 client (Web application) with redirect URI `${APP_URL}/youtube/broadcaster/callback`. Set `YOUTUBE_OAUTH_CLIENT_ID`, `YOUTUBE_OAUTH_CLIENT_SECRET` and `YOUTUBE_OAUTH_REDIRECT_URL`. Add the scopes `youtube.force-ssl`, `youtube.readonly` and `yt-analytics.readonly` to the consent screen.
3. **Publish the consent screen "In production".** In "Testing", Google's refresh tokens expire after 7 days. Expect the unverified-app screen: only the channel owners connect, so the 100-user cap doesn't matter.
4. **Enable the YouTube Analytics API** in that project (#126, #144). It is separate from the Data API.
5. **Each channel owner** signs in to ARE as a broadcaster and opens `/youtube/broadcaster/connect` with the Google account that owns the channel, once per channel.
6. **Replies on YouTube** cost 50 quota units each, so they are off until you set `CHAT_REPLIES_YOUTUBE=true`. Optionally cap them with `CHAT_REPLIES_YOUTUBE_PER_STREAM` (default 40).
7. **Show windows** (#140): set `YOUTUBE_SHOW_WINDOWS` (for example `sun 17:00-21:00,wed 18:00-20:00`) and `YOUTUBE_SHOW_TIMEZONE` (for example `America/Denver`). Inside a window, the scheduler runs `youtube:chat --auto` to find each channel's live video.
8. **The YouTube API audit isn't needed yet.** It is needed for Shorts upload (#11, #25), which isn't on `main`.

### 1.9 Tokens

Each token is printed **once** and stored only as a hash. Treat each one like a password.

- **OBS overlays** (#50, #74): for each overlay you use, run:

  ```sh
  php artisan overlay:token queue
  ```

  The overlays are `queue`, `vote`, `now-playing`, `captions`, `visualizer`, `top-vote`, `cta` and `bus` (the Chat Control Bus vote, #154). Paste the printed `…#token=…` URL into an OBS browser source of the matching size (1920×1080 or 1080×1920). Once the Laravel log shows no "Overlay token passed in the query string" warnings, set `ARE_OVERLAY_ALLOW_QUERY_TOKEN=false`.
- **CTA lower-third** (#50): set `ARE_CTA_ORKESTERA_URL` and `ARE_CTA_EDOS_URL`, because an item with no URL isn't shown. Have someone approve the copy in `config/are.php` before it goes on stream.
- **Bus adapters** (#123): `php artisan bus:token orkestera` issues the token a game adapter polls with.
- **Music player** (#141): `php artisan music:player-token obs` issues the token a local player (OBS script, Mac Shortcut) sends to `POST /music/requests/advance` as `Authorization: Bearer <token>`.

### 1.10 The streaming machine (OBS)

For the visualizer to follow show audio (#72):

1. Install a virtual audio device (BlackHole on macOS, VB-CABLE on Windows). In OBS, set Settings → Audio → Advanced → Monitoring Device to it, and set the sources the visualizer should follow to "Monitor and Output".
2. Launch OBS with `--enable-media-stream`. On macOS, also grant OBS the Microphone permission.
3. Append `&audio=<device label>` (for example `&audio=BlackHole`) to the visualizer overlay URL, and leave "Control audio via OBS" off.

### 1.11 Environment variables

Set these in Forge's Environment panel, then run `php artisan optimize`. The defaults are those in `.env.example` and `config/`.

| Variable | Purpose | PR |
|---|---|---|
| `APP_ENV`, `APP_URL`, `APP_KEY`, `APP_DEBUG` | `production`, the public https URL, a generated key (rotating it changes chatter hashes, #119), and `false` | |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL | #64 |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `redis` / `redis` with Horizon, or `database`; sessions stay `database` | #40 |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_CACHE_DB` | Shared Redis; ARE's own indexes (4 and 5) | #40 |
| `BROADCAST_CONNECTION`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_SERVER_PORT`, `REVERB_ALLOWED_ORIGINS` | Realtime | #48 |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Compiled into the browser bundle; rebuild after changing | #48 |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Lead notifications | #70 |
| `TRUSTED_PROXIES` | Only behind a load balancer or CDN | #30 |
| `SENTRY_LARAVEL_DSN` | Error reporting (not in `.env.example`) | |
| `TWITCH_CLIENT_ID`, `TWITCH_CLIENT_SECRET` | The Twitch app | |
| `TWITCH_REDIRECT_URL`, `TWITCH_BROADCASTER_REDIRECT_URL` | OAuth callbacks registered with Twitch | |
| `TWITCH_CHANNEL_ID`, `TWITCH_BROADCASTER_IDS`, `TWITCH_FRIEND_IDS` | Primary channel, extra served channels (added to the committed list), friend channels | |
| `TWITCH_HELIX_EVENTSUB_SECRET`, `TWITCH_EVENTSUB_CALLBACK_URL` | EventSub signing secret and public callback | #37 |
| `CHAT_COMMANDS_PER_MINUTE` | Commands per person per minute (10) | #77 |
| `CHAT_REPLIES_ENABLED`, `CHAT_REPLIES_PER_CHANNEL`, `CHAT_REPLIES_WINDOW_SECONDS`, `CHAT_REPLIES_MAX_AGE_SECONDS` | Chat replies on or off (on), per-channel cap (20 per 30 s), freshness (30 s) | #93 |
| `CHAT_REPLIES_YOUTUBE`, `CHAT_REPLIES_YOUTUBE_PER_STREAM` | YouTube replies (off) and their per-stream cap (40) | #126 |
| `YOUTUBE_API_KEY`, `YOUTUBE_CHANNEL_IDS` | Reading YouTube live chat | #91 |
| `YOUTUBE_POLL_FLOOR_MS`, `YOUTUBE_QUOTA_DAILY_UNITS`, `YOUTUBE_QUOTA_DAILY_SEARCH_CALLS`, `YOUTUBE_QUOTA_ALERT_RATIO` | Polling floor and quota budget | #91 |
| `YOUTUBE_OAUTH_CLIENT_ID`, `YOUTUBE_OAUTH_CLIENT_SECRET`, `YOUTUBE_OAUTH_REDIRECT_URL` | Google OAuth client for replies and analytics | #126 |
| `YOUTUBE_SHOW_WINDOWS`, `YOUTUBE_SHOW_TIMEZONE`, `YOUTUBE_AUTO_SEARCH_EVERY_MINUTES` | When `youtube:chat --auto` looks for the live video | #140 |
| `MUSIC_DISK`, `MUSIC_MAX_UPLOAD_KB` | Private music disk and upload cap | #43 |
| `MUSIC_REQUESTS_PER_USER`, `MUSIC_SONG_REQUEST_REWARD_ID` | `!song` cap (2) and song-reward ids, one per channel | #107 |
| `CLIPS_PER_CHANNEL_PER_MINUTE`, `CLIPS_CREATE_DELAY_SECONDS`, `CLIPS_DOWNLOAD_URL_TTL_SECONDS` | `!clip` limits and timing | #95 |
| `CLIPS_DISK`, `CLIPS_MAX_FILE_BYTES`, `CLIPS_DOWNLOAD_HOSTS` | Where clip files go, their size cap and allowed hosts | #142 |
| `CLIPS_KEEP_REJECTED_DAYS`, `CLIPS_KEEP_APPROVED_DAYS`, `CLIPS_DISK_WARN_BYTES`, `CLIPS_DISK_MIN_FREE_BYTES` | Clip retention and the readiness page's disk thresholds | #145 |
| `BUS_ENABLED`, `BUS_PLATFORMS`, `BUS_LATENCY_TWITCH`, `BUS_LATENCY_YOUTUBE`, `BUS_LATENCY_FACEBOOK` | Deploy-time bus switch; platforms feeding it and their chat lag | #123 |
| `BUS_ORKESTERA_MODE`, `BUS_ORKESTERA_WINDOW_SECONDS`, `BUS_APPROVAL_TIMEOUT_SECONDS`, `BUS_KILL_UNDO_SECONDS`, `BUS_MAX_REPLAY_SECONDS` | Orkestera's default mode and window; approval timeout; kill-switch undo; adapter replay | #123 |
| `ARE_OVERLAY_GRANT_SECONDS`, `ARE_OVERLAY_ALLOW_QUERY_TOKEN` | Overlay grant lifetime; turn the old `?token=` URLs off | #74 |
| `ARE_CTA_ROTATE_SECONDS`, `ARE_CTA_ORKESTERA_URL`, `ARE_CTA_ORKESTERA_DISPLAY_URL`, `ARE_CTA_EDOS_URL`, `ARE_CTA_EDOS_DISPLAY_URL` | The CTA lower-third | #50 |
| `ARE_LEADS_NOTIFY`, `ARE_LEADS_WEBHOOK_URL` | Who hears about `/about` enquiries | #70 |
| `ARE_WEEKLY_SUMMARY_WEBHOOK_URL`, `ARE_WEEKLY_SUMMARY_DAY`, `ARE_WEEKLY_SUMMARY_TIME` | Weekly attribution summary (Mondays 09:00 UTC) | #108 |
| `ARE_CHAT_ORKESTERA_URL`, `ARE_CHAT_EDOS_URL`, `ARE_CHAT_LINK_COOLDOWN_SECONDS` | `!orkestera` and `!edos` links | #87 |

---

## 2. Show-day preflight

Start at least an hour before going live.

1. **`/admin/readiness` is all green.** Fix every red line with the fix it names, then reload the page. A green Twitch group means every channel is connected with every scope and all EventSub subscriptions are enabled.
2. **Queue and scheduler:** the readiness page shows no stale database jobs, Horizon running (with Redis), and a scheduler heartbeat under a minute old. With Horizon, also check that `/horizon` shows both supervisors.
3. **YouTube chat:**
   - With show windows set, `youtube:chat --auto` starts on its own once both streams are live. Without them, start it by hand once both streams are live:

     ```sh
     php artisan youtube:chat --auto
     ```

     or name the videos:

     ```sh
     php artisan youtube:chat <video id or URL> <video id or URL>
     ```
   - Check the quota with `php artisan youtube:quota`.
4. **OBS:** each overlay source loads (a blank source means a missing or rotated token; re-issue it with `overlay:token`), the visualizer reacts to audio, and the local player has its `music:player-token`.
5. **Bus** (`/bus`): pick the running game (or None), check its mode (democracy, weighted random or anarchy), and check that the kill switch is off. If the game adapter polls, check that it has its `bus:token`.
6. **Moderator duty.** Name who covers each of these, for the whole show:
   - **Bus approvals:** every free-text action (Orkestera's `task`) waits under *Waiting for approval* on `/bus`, and is rejected after `BUS_APPROVAL_TIMEOUT_SECONDS` (120 s) if nobody decides. Moderator approval is the real control on free text.
   - **Clips:** `/clips` to approve, edit or reject `!clip` markers.
   - **Songs:** `/music/requests` to play, skip and clear requests.
   - **Moderation:** `/moderation` for bans, merges and the audit log.

   Moderators are the Twitch moderators of the served channels. If one is missing, run `php artisan twitch:sync-moderation`.

## 3. During the show

- **Kill switch (bus):** on `/bus`, **Kill switch** stops publishing for every game, cancels open votes and approvals, and vetoes actions delivered in the last `BUS_KILL_UNDO_SECONDS`. Any moderator can throw it; only a broadcaster can reset it. Without the web UI, run:

  ```sh
  php artisan bus:kill
  php artisan bus:kill --off
  ```

  The first throws it and the second resets it. Both appear in the audit log as `CLI (bus:kill)` and `CLI (bus:kill --off)` (#149).
- **Pause a game:** **Pause** and **Resume** on `/bus`. Pausing one game leaves the others running. **Veto** removes an option from the open vote, or tells adapters to undo an action already sent.
- **Songs:**
  - On `/music/requests`, **Done, play next** finishes the request on air and starts the next one. **Skip** drops a request, and **Clear queue** skips every waiting one. A channel-point request skipped or cleared before it plays is refunded automatically (#134); one already on air is not.
  - In chat, moderators can type `!np next` or `!np done`. The local player calls the advance hook:

    ```sh
    curl -fsS -X POST -H "Authorization: Bearer $ARE_PLAYER_TOKEN" https://appliedresearchequity.com/music/requests/advance
    ```
- **Clips:** review new markers on `/clips` as they arrive. Approve, edit or reject each one.
- **Stream title:** `php artisan twitch:title "New title"` (add `--broadcaster=<id>` for another channel).

## 4. Incidents

### A 500 after a deploy

Production disables opcode timestamp checks, so web requests can keep running old classes after a deploy that stopped half-way.

```sh
php artisan view:clear
php artisan optimize
sudo -n service php8.3-fpm reload
```

Then check `storage/logs/laravel.log` and Sentry. If the deploy stopped before migrating, run `php artisan config:clear`, then `php artisan migrate --force`, then `php artisan cache:clear`, and reload FPM again.

### The queue is backing up

Symptoms: chat commands don't answer, votes lag, and the readiness page's Queue group is red.

1. Open `/admin/readiness`. The Queue group shows waiting jobs by queue and how old they are.
2. **With Horizon:** run `php artisan horizon:status` and open `/horizon`. If Horizon isn't running, restart the Horizon daemon in Forge, or run `php artisan horizon:terminate` and let Supervisor start it.
3. **With database workers:** check that one Forge daemon runs `php artisan queue:work database --queue=broadcasts,default`, and a separate one runs `nice -n 10 php artisan queue:work database-long --queue=clips --timeout=600`. A backlog on the `clips` queue with an empty `broadcasts` queue means the second one is missing.
4. Jobs left in the database queue after a move to Redis: drain them once with `php artisan queue:work database --stop-when-empty`, and any Shorts cuts with `php artisan queue:work database-long --queue=clips --timeout=600 --stop-when-empty`.
5. Failed jobs: list them with `php artisan queue:failed`. Once the cause is fixed, run `php artisan queue:retry all`.
6. Old broadcast rows from before #109, while broadcasting is off, are safe to delete: `DELETE FROM jobs WHERE queue = 'broadcasts';`.

### The YouTube quota is exhausted

1. Run `php artisan youtube:quota`. The quota day resets at midnight Pacific Time.
2. To save the rest of the day's quota:
   - turn off YouTube replies with `CHAT_REPLIES_YOUTUBE=false`, then `php artisan optimize`;
   - and, if needed, stop reading chat with `php artisan youtube:chat --stop`.

   Twitch is unaffected.
3. A chat that ended with `forbidden` or `insufficientPermissions` in `youtube_live_chats.end_reason` means the API key can't read chat (#91), and reading needs a follow-up.

### A leaked token

Rotate it. The old one stops working at once, so update whatever used it.

| Leaked | Rotate with | Then |
|---|---|---|
| An OBS overlay URL | `php artisan overlay:token <overlay> --rotate` | Paste the new URL into OBS. The old source goes blank within about 90 s |
| A music player token | `php artisan music:player-token <name> --rotate`, or `--revoke` to remove it | Update the player's `Authorization` header |
| A bus adapter token | `php artisan bus:token <game> --rotate` | Update the adapter |
| Reverb keys | New `REVERB_APP_KEY` and `REVERB_APP_SECRET` in Forge | Redeploy, so the bundle is rebuilt |

### Rollback

1. **Preferred:** revert the bad PR on the fork (`git revert`, through a PR). CI, `deploy-sync` and Forge then deploy the revert like any change.
2. **If the bad PR added migrations,** roll them back **before** the old code goes live, with as many steps as the PR added, for example `php artisan migrate:rollback --step=2`. Read the PR's operator section first: a migration that drops data (#45) can't be rolled back without a backup.
3. **If production must move before a revert can merge,** run this in the site directory. The production checkout has no local changes, so `reset --hard` loses nothing there.

   ```sh
   php artisan down
   git reset --hard <last good commit>
   bash scripts/forge-deploy.sh
   php artisan up
   ```

   Revert on the fork straight after, or the next deploy fast-forwards to the bad commit again.

---

## PRs behind this runbook

#30 /about and short links · #37 EventSub · #40 Redis and Horizon · #43 music catalogue · #45 identities · #48 Reverb · #50 overlays · #60 `npm ci` · #64 SQLite cutover · #65 deploy script · #70 lead mail · #72 visualizer audio · #74 fragment tokens · #77 chat commands · #86 test guard · #87 tracked links · #91 YouTube chat · #92 sub end · #93 chat replies · #95 `!clip` · #97 attribution cookie · #107 song requests · #108 weekly summary · #109 broadcast jobs · #115 live overlays · #119 viewer stats · #123 Chat Control Bus · #126 YouTube replies · #129 and #134 refunds · #139 readiness page · #140 show windows · #141 player hook · #142 and #145 clip files · #144 YouTube Analytics · #149 CLI audit names · #151 YouTube channel ids · #154 bus overlay
