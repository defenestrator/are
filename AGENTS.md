# Working on ARE

Read [CONTRIBUTING.md](CONTRIBUTING.md) for the loop: claim, branch, test, PR, human merge. This file covers how to write code here.

## Read the docs from disk first

Before you search the web, read the framework docs vendored under `docs/vendor/`. They are pinned to the versions in `composer.lock`, as listed in `docs/vendor/SOURCES.md`:

| Topic | Path |
|---|---|
| Laravel 12 (including Reverb, Horizon, Sanctum, Socialite, queues, broadcasting) | `docs/vendor/laravel/*.md` |
| Livewire 3 | `docs/vendor/livewire/*.md` |
| Volt | `docs/vendor/livewire/volt.md`, `docs/vendor/volt/README.md` |
| Pest 3 | `docs/vendor/pest/*.md` |
| Flux 2 (not open source) | Component stubs in `vendor/livewire/flux/stubs/resources/views/flux/` |

`grep -ril 'eventsub\|broadcast' docs/vendor/laravel` is faster than a search engine. Use the web only for third-party APIs (Twitch Helix, YouTube Data API, Forge) and cite primary sources. Refresh the docs with `scripts/update-vendor-docs.sh` and never edit them by hand.

## Laravel conventions this repo follows

- **Generate, don't hand-write.** Use `php artisan make:*` (`model -mf`, `migration`, `job`, `event`, `listener`, `policy`, `request`, `command`, `test --pest`, and `livewire:make` or `make:volt`) so files land where Laravel expects.
- **Thin routes and controllers.** Validate with Form Requests or Livewire `#[Validate]`. Authorise with policies and gates (`can:` middleware, `$this->authorize()`), not inline `if` checks. Name every route and link with `route()`.
- **Eloquent first.** Use relationships, scopes, casts and `$fillable`. Eager-load to avoid N+1 queries, and turn on `Model::preventLazyLoading()` outside production. Avoid raw SQL unless a query genuinely needs it.
- **Migrations are forward-only and reversible.** Never edit a migration that has shipped; add a new one. Give each migration a working `down()`, and back-fill data inside the migration that changes its shape.
- **Slow or external work goes on the queue.** Anything that calls Twitch, YouTube or another remote API runs in a `ShouldQueue` job that is idempotent and safe to retry. Use the `Http` client with timeouts and `retry()`, never raw Guzzle.
- **Config, not `env()`.** Call `env()` only in `config/*.php`. Code reads `config('…')`, so config caching works on Forge. Add new keys to `.env.example`.
- **Events for cross-cutting effects.** Domain events (`QuestionSubmitted`, `VoteCast`, …) fan out to broadcasts, analytics and the bus. Do not call those systems directly from a component.
- **Livewire and Volt.** Keep state small and `#[Locked]` where the client must not change it. Authorise inside every action method, because actions are public endpoints. Prefer `wire:model.live.debounce` and computed properties to re-querying in `render()`.
- **Security defaults.** Every state-changing route is POST/PUT/DELETE with CSRF. Rate-limit public inputs with `RateLimiter`. Compare secrets with `hash_equals`. Store tokens hashed, or encrypted with the `encrypted` cast. Never log tokens.

## Testing and the gate

- Pest feature tests on in-memory SQLite (`phpunit.xml`). Use factories, not hand-built rows. Use `Http::fake`, `Queue::fake`, `Event::fake` and `Notification::fake` at the edges, and never make real network calls in tests.
- Each change ships with tests that pin its behaviour. Each fix ships with a test that fails before the fix.
- Before you push, run `./vendor/bin/pest`, `./vendor/bin/phpstan analyse` and `./vendor/bin/pint --dirty`. Report the real pass and fail counts in the PR.
- A fresh worktree needs `composer install` and `cp .env.example .env && php artisan key:generate`. Run `npm ci && npm run build` only when you change frontend assets.
- Browser smoke tests live in `e2e/` (Playwright, #155). CI's `e2e` job serves the app against Postgres with `APP_ENV=testing`, seeds it with `E2eSeeder`, and fails on any console error or warning, any 4xx/5xx, or an overlay token in a request URL. A new page or overlay gets a spec there. Signing in uses `/_e2e/login/{user}`, which exists only when `APP_ENV=testing`. Running the suite locally starts a server, so it needs the `dev-stack` lock: `npx playwright install chromium`, then `npm run build && npm run test:e2e` against a seeded testing database.

## Shared resources

Do not start long-running dev servers, Reverb, queue workers or a shared database unless the operator has granted you the `dev-stack` lock. Never enqueue or merge PRs (`merge-queue`). Never run `git stash`, because every worktree shares one stash stack.
