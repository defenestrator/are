# Load test (#176)

`are.js` is a [k6](https://k6.io/) script. **Run it from a machine that is not the server under test.** A load generator on the app box competes with PHP-FPM for CPU, so its numbers say little.

It reports p50, p95 and p99 latency and the error rate for each scenario, plus a breakdown by request (first page loads versus polls), on stdout and in `loadtest-summary.md`. Thresholds fail the run with exit code 99. In CI that is reported as a warning for now (see below).

## Scenarios

| Scenario | What it does | Rate (defaults) |
|---|---|---|
| `anonymous` | `GET /`, `/about`, `/music`, `/up` at random | `ANON_RATE` = 20/s |
| `overlays` | `OVERLAY_SOURCES` OBS sources (queue, vote, top-vote, now-playing, bus). Each trades its overlay token for a grant once, then polls the way the page does without a socket: a Livewire `$refresh` every 5–10 s (now-playing every 5 s) | 10 sources |
| `vote` | `VIEWERS` signed-in viewers on `/vote`. Each polls like the page's fallback (`refreshQueue` every 5–10 s, which renders only when something changed; #180) and votes on a question 15% of the time, through the page component with the question's id | 50 viewers |
| `chat` | A burst of signed EventSub `channel.chat.message` webhooks carrying `!vote` (70%) and `!q` (30%) from the seeded chatters: a 5 s ramp, 20 s at peak, a 5 s ramp down, starting 15 s in | `CHAT_RATE` = 40/s peak |

Each scenario runs for `DURATION` (60 s by default).

**Thresholds** (profile `ci`):

| Scenario | p95 | p99 | Errors |
|---|---|---|---|
| `anonymous` | < 1000 ms | < 2000 ms | < 1% |
| `overlays` | < 1500 ms | < 3000 ms | < 1% |
| `vote` | < 2000 ms | < 4000 ms | < 1% |
| `chat` | < 750 ms | < 1500 ms | < 1% |

Every check must also pass 99% of the time. These are baselines for comparing runs of the CI job, not production targets.

## CI and staging (profile `ci`)

The `vote` scenario signs viewers in through the **test-only** `/_e2e/login/{user}` route (#155). That route exists only when `APP_ENV=testing`, so this profile runs against **CI or a staging instance, never production**. The scenario aborts if the route is missing.

The `chat` scenario writes questions and votes, and the `overlays` scenario mints overlay grants. Neither belongs on production either.

To run it against a staging instance with `APP_ENV=testing`:

```sh
php artisan migrate:fresh --force
LOADTEST_VIEWERS=50 php artisan db:seed --class=LoadtestSeeder --force   # writes scripts/loadtest/.fixtures.json
k6 run -e BASE_URL=https://staging.example -e EVENTSUB_SECRET=<TWITCH_HELIX_EVENTSUB_SECRET> scripts/loadtest/are.js
```

Copy `.fixtures.json` to the machine that runs k6. It holds that instance's overlay tokens, so treat it as a secret.

**In CI:** the **Load test** workflow (`.github/workflows/loadtest.yml`) does all of this on a runner.
- It uses `artisan serve` with 8 workers, a Postgres service and a `queue:work` worker on `broadcasts,default`.
- **OPcache is on, as in production** (#188): `opcache.enable_cli=1` and `opcache.validate_timestamps=0`, set through `setup-php`. The built-in server is the CLI SAPI, so without `enable_cli` every request recompiled the framework. The job proves it with a throwaway probe in `public/`, checked before the run and recorded after it (hit rate about 99.8%). The run fails if OPcache is off.
- Start it from the Actions tab (**Run workflow**), where duration, viewers, overlay sources and rates are inputs. It also runs on pull requests that change the load test.
- It is not in `ci-gate`.
- The run's summary has the tables, plus how long the worker took to drain the chat burst.
- **Thresholds are informational for now.** A crossed threshold (k6 exit 99) makes the job warn, not fail, while #173, #179 and #180 work on the costs it measures. The thresholds are unchanged. A script error or an aborted run still fails the job.
- **All `vote` viewers and overlay sources start at once,** as when a link drops in chat. Their first page loads (`/vote` is the costliest) queue behind each other, and most of the p95/p99 tail comes from that opening burst. The breakdown table shows it.

`php artisan serve` is not PHP-FPM, the runner is shared, and in CI k6 runs on the same runner as the app: exactly the compromise this script exists to avoid elsewhere. CI numbers compare CI runs with each other; they are not production numbers. They also vary from run to run on a busy runner, so compare medians of a few runs, not a single one.

Each virtual user is one browser, so its cookies (its session) last across iterations (`noCookiesReset`). Without that, k6 empties the jar every iteration, and every poll after the first gets a 419.

### CI baseline (#188)

Two dispatched runs of the default load: 60 s, 50 viewers, 10 overlay sources, anonymous 20/s, chat burst to 40/s. Both used OPcache, on `main` after #187 (`5be834d`). With two runs, the median is the mean of the pair. Both runs are shown, because a shared runner varies.

| Scenario | p50 | p95 | p99 | Errors | Run 37289490523 (p50 / p95 / p99) | Run 37289782033 (p50 / p95 / p99) |
|---|---|---|---|---|---|---|
| anonymous | 18 ms | 220 ms | 891 ms | 0.00% | 21 / 392 / 1093 ms | 14 / 48 / 688 ms |
| overlays | 36 ms | 940 ms | 1023 ms | 0.00% | 45 / 1133 / 1239 ms | 27 / 747 / 807 ms |
| vote | 80 ms | 975 ms | 1082 ms | 0.00% | 112 / 1159 / 1255 ms | 47 / 791 / 908 ms |
| chat | 28 ms | 192 ms | 269 ms | 0.00% | 41 / 354 / 448 ms | 15 / 29 / 89 ms |

**How the two runs went:**
- Every threshold passed in both runs, and 100% of checks passed.
- The worker drained the chat burst within the run (0 jobs left, 0 failed).
- The p95/p99 tails are still the opening burst. The first `/vote` sign-in and page load had p50 470–692 ms and p95 913–1195 ms, and the first overlay page load had p50 707–1017 ms. Polls after that are fast: the vote `refreshQueue` p50 was 40–98 ms.

**Compared with the runs before OPcache and #187:** the good runs then had vote p95 about 2.0–2.2 s, and others tipped into overload (all of them are listed on #178). The change comes from OPcache and from #187's lighter `/vote` together. These runs don't separate the two.

## Production (profile `production-safe`): read-only, low rate

```sh
k6 run -e BASE_URL=https://are.example -e PROFILE=production-safe scripts/loadtest/are.js
```

- **What runs:** only `anonymous`, which is `GET /`, `/about`, `/music` and `/up`. These are read-only and need no account or token.
- **Rate:** 1 request a second for 2 minutes by default. `ANON_RATE` can raise it to at most **3/s**, which the script enforces.
- **Refused scenarios:** the script refuses `overlays`, `vote` and `chat` under this profile, and reads no fixtures.
- **When to run it:** outside show hours, from a machine that is not the server. Watch the readiness page and the server's load while it runs. Stop it (Ctrl-C) if p95 climbs or errors appear.
- **What not to point at production:** never run `PROFILE=ci` or `LoadtestSeeder` against production.
  - `LoadtestSeeder` refuses outside `APP_ENV=testing`.
  - The test-only login does not exist there, so `vote` would abort.
  - `chat` would post questions into the real queue if production's EventSub secret were supplied.

## Options

All options are `-e NAME=value`.

| Option | Default | Notes |
|---|---|---|
| `BASE_URL` | none (required) | The instance to test |
| `PROFILE` | `ci` | Or `production-safe` |
| `SCENARIOS` | all for the profile | For example `anonymous,overlays` |
| `DURATION` | `60s` (`2m` for production-safe) | |
| `ANON_RATE` | 20 (production-safe: 1, at most 3) | Requests/s |
| `OVERLAY_SOURCES` | 10 | |
| `VIEWERS` | 50 | Seed at least as many with `LOADTEST_VIEWERS` |
| `CHAT_RATE` | 40 | Peak webhooks/s |
| `EVENTSUB_SECRET` | none | The instance's `TWITCH_HELIX_EVENTSUB_SECRET`, for `chat` |
| `FIXTURES` | `./.fixtures.json` | Relative to the script |
| `SUMMARY_PATH`, `SUMMARY_JSON` | `loadtest-summary.md`, `.json` | Relative to the working directory |
