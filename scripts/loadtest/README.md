# Load test (#176)

`are.js` is a [k6](https://k6.io/) script. **Run it from a machine that is not the server under test.** A load generator on the app box competes with PHP-FPM for CPU, so its numbers say little.

It reports p50, p95 and p99 latency and the error rate for each scenario, on stdout and in `loadtest-summary.md`. Thresholds fail the run with exit code 99.

## Scenarios

| Scenario | What it does | Rate (defaults) |
|---|---|---|
| `anonymous` | `GET /`, `/about`, `/music`, `/up` at random | `ANON_RATE` = 20/s |
| `overlays` | `OVERLAY_SOURCES` OBS sources (queue, vote, top-vote, now-playing, bus). Each trades its overlay token for a grant once, then polls the way the page does without a socket: a Livewire `$refresh` every 5–10 s (now-playing every 5 s) | 10 sources |
| `vote` | `VIEWERS` signed-in viewers on `/vote`. Each polls like the page's fallback (a `$refresh` every 5–10 s) and votes on a question card 15% of the time | 50 viewers |
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
- Start it from the Actions tab (**Run workflow**), where duration, viewers, overlay sources and rates are inputs. It also runs on pull requests that change the load test.
- It is not in `ci-gate`.
- The run's summary has the table, plus how long the worker took to drain the chat burst.

`php artisan serve` is not PHP-FPM, and the runner is shared. CI numbers compare CI runs with each other; they are not production numbers.

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
