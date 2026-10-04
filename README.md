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

Moderators of any served channel get the same admin powers as the broadcaster. Anyone banned or timed out on a served channel can't log in, submit or vote until the ban lifts.
## OBS overlays

Each overlay is a transparent page for an OBS browser source, at `/overlay/{name}` where the name is `queue`, `vote`, `top-vote`, `now-playing`, `captions`, `visualizer` or `cta`. Add `?layout=horizontal` for a 1920×1080 source or `?layout=vertical` for a 1080×1920 one.

Every overlay has its own token. `php artisan overlay:token queue` prints the horizontal and vertical URLs once. Only a hash is stored, so the URL can't be shown again. To replace a leaked URL, run `php artisan overlay:token queue --rotate`; the old URL stops working, and any open copy goes blank on its next refresh. The queue, vote and top-vote overlays refresh every 5 seconds.

The `cta` lower-third rotates between the Orkestera and EDOS Professional Services calls to action. Set `ARE_CTA_ORKESTERA_URL` and `ARE_CTA_EDOS_URL`: an item with no URL is not shown. The copy lives in `config/are.php`.
