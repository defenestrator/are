<?php

namespace App\Readiness;

use App\Agent\AgentTokens;
use App\Clips\ClipStorage;
use App\Clips\Ffmpeg;
use App\ControlBus\Game;
use App\Models\BroadcasterToken;
use App\Models\BusAdapterToken;
use App\Models\BusControl;
use App\Models\MusicPlayerToken;
use App\Models\User;
use App\Models\YouTubeChannelToken;
use App\Twitch;
use App\YouTube\Quota;
use App\YouTube\YouTubeApi;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Everything an operator must set up before a show, as checks (#135).
 *
 * Each check is cheap, and anything that leaves the box is time-boxed
 * (Helix, Redis, the Reverb socket). A check that throws becomes a red line
 * naming the exception class, never its message, so one broken check never
 * hides the others or leaks a value.
 */
class ReadinessChecks
{
    /**
     * The queues a worker must cover, in priority order. Vote updates, topic
     * changes and Chat Control Bus windows ride `broadcasts`; everything else
     * is `default`. A worker on `default` alone leaves the live views frozen.
     */
    public const WORKER_QUEUES = 'broadcasts,default,clips';

    /** A ready database job older than this means no worker is draining the queue. */
    public const QUEUE_STALE_SECONDS = 120;

    /** The scheduler runs every minute; allow for one missed tick. */
    public const HEARTBEAT_WARN_SECONDS = 150;

    public const HEARTBEAT_FAIL_SECONDS = 600;

    public const HELIX_TIMEOUT_SECONDS = 4;

    public function __construct(private Probes $probes) {}

    /**
     * @return array<string, list<Check>> group title => checks
     */
    public function all(): array
    {
        return [
            'Twitch' => $this->guard('Twitch', fn () => $this->twitch()),
            'Queue' => $this->guard('Queue', fn () => $this->queue()),
            'Redis' => $this->guard('Redis', fn () => [$this->redis()]),
            'Live updates (Reverb)' => $this->guard('Live updates', fn () => $this->broadcasting()),
            'Scheduler' => $this->guard('Scheduler', fn () => [$this->scheduler()]),
            'YouTube' => $this->guard('YouTube', fn () => $this->youtube()),
            'Chat Control Bus' => $this->guard('Chat Control Bus', fn () => $this->controlBus()),
            'Music player' => $this->guard('Music player', fn () => [$this->musicPlayer()]),
            'Mail and leads' => $this->guard('Mail and leads', fn () => $this->mail()),
            'Clips' => $this->guard('Clips', fn () => [ClipStorage::readinessCheck(), Ffmpeg::readinessCheck()]),
            'VTuber agent' => $this->guard('VTuber agent', fn () => [AgentTokens::readinessCheck()]),
            'Deploy' => $this->guard('Deploy', fn () => $this->deploy()),
            'Error reporting' => $this->guard('Error reporting', fn () => [$this->sentry()]),
        ];
    }

    /**
     * @param  array<string, list<Check>>  $groups
     */
    public static function worst(array $groups): Status
    {
        $worst = Status::Skip;
        foreach ($groups as $checks) {
            foreach ($checks as $check) {
                if ($check->status->severity() > $worst->severity()) {
                    $worst = $check->status;
                }
            }
        }

        return $worst;
    }

    // Twitch ------------------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function twitch(): array
    {
        $checks = [];
        $appSet = filled(config('services.twitch.client_id')) && filled(config('services.twitch.client_secret'));
        $checks[] = $appSet
            ? Check::ok('Twitch app credentials', 'TWITCH_CLIENT_ID and TWITCH_CLIENT_SECRET are set.')
            : Check::fail('Twitch app credentials', 'TWITCH_CLIENT_ID or TWITCH_CLIENT_SECRET is not set.', 'Set both in .env from the Twitch developer console, then php artisan optimize.');

        $broadcasters = User::getBroadcasterIDs();
        if ($broadcasters === []) {
            $checks[] = Check::fail('Served channels', 'No channel is configured.', 'Set TWITCH_CHANNEL_ID (and TWITCH_BROADCASTER_IDS for more channels).');

            return $checks;
        }

        $tokens = BroadcasterToken::whereIn('broadcaster_id', $broadcasters)->get()->keyBy('broadcaster_id');
        $reconnect = 'Sign in as that broadcaster and open '.route('twitch.broadcaster.connect').'.';

        foreach ($broadcasters as $broadcasterId) {
            $name = "Channel {$broadcasterId}: connection and scopes";
            $token = $tokens->get($broadcasterId);

            if ($token === null) {
                $checks[] = Check::fail($name, 'The broadcaster has not connected this channel.', $reconnect);

                continue;
            }

            $missing = array_values(array_diff(Twitch::BROADCASTER_SCOPES, (array) $token->scopes));
            $checks[] = $missing === []
                ? Check::ok($name, 'Connected with every scope ARE needs.')
                : Check::fail($name, count($missing).' scope(s) missing, granted before newer features needed them.', 'Reconnect to grant them. '.$reconnect, $missing);
        }

        $checks[] = $this->eventSub($broadcasters, $appSet);

        return $checks;
    }

    /**
     * @param  list<string>  $broadcasters
     */
    private function eventSub(array $broadcasters, bool $appSet): Check
    {
        $name = 'EventSub subscriptions';

        if (! filled(config('services.twitch.eventsub_secret'))) {
            return Check::fail($name, 'TWITCH_HELIX_EVENTSUB_SECRET is not set, so Twitch cannot sign its webhooks.', 'Run php artisan twitch:generate-event-sub-key, then php artisan twitch:eventsub-subscribe.');
        }

        $callback = config('services.twitch.eventsub_callback') ?: route('twitch.eventsub');
        if (! str_starts_with((string) $callback, 'https://')) {
            return Check::fail($name, 'The EventSub callback is not HTTPS, which Twitch requires.', 'Set TWITCH_EVENTSUB_CALLBACK_URL (or APP_URL) to the public https:// address.');
        }

        if (! $appSet) {
            return Check::skip($name, 'Needs the Twitch app credentials to ask Helix.');
        }

        $subscriptions = $this->helixSubscriptions();
        $missing = [];
        $broken = [];

        foreach ($broadcasters as $broadcasterId) {
            foreach (Twitch::EVENTSUB_TYPES as $type) {
                [$version, $condition] = Twitch::eventSubDefinition($type, $broadcasterId);
                $match = collect($subscriptions)->first(fn (array $sub) => ($sub['type'] ?? null) === $type
                    && ($sub['version'] ?? null) === $version
                    && ($sub['transport']['callback'] ?? null) === $callback
                    && array_intersect_assoc($condition, (array) ($sub['condition'] ?? [])) === $condition);

                if ($match === null) {
                    $missing[] = "{$broadcasterId}: {$type}";
                } elseif (($match['status'] ?? null) !== 'enabled') {
                    $broken[] = "{$broadcasterId}: {$type} ({$match['status']})";
                }
            }
        }

        $expected = count($broadcasters) * count(Twitch::EVENTSUB_TYPES);
        if ($missing === [] && $broken === []) {
            return Check::ok($name, "All {$expected} subscriptions are enabled.");
        }

        return Check::fail(
            $name,
            (count($missing) + count($broken))." of {$expected} subscriptions are missing or not enabled.",
            'Run php artisan twitch:eventsub-subscribe. A 403 there means the broadcaster must reconnect for new scopes.',
            array_merge(array_map(fn ($line) => "missing {$line}", $missing), array_map(fn ($line) => "not enabled {$line}", $broken)),
        );
    }

    /**
     * Every EventSub subscription for this app's client id, at most 10 pages.
     *
     * @return list<array<string, mixed>>
     */
    private function helixSubscriptions(): array
    {
        $token = Twitch::appAccessToken();
        $subscriptions = [];
        $cursor = null;

        for ($page = 0; $page < 10; $page++) {
            $response = Http::withHeaders(['Client-ID' => config('services.twitch.client_id')])
                ->withToken($token)
                ->connectTimeout(self::HELIX_TIMEOUT_SECONDS)
                ->timeout(self::HELIX_TIMEOUT_SECONDS)
                ->get(Twitch::HELIX.'/eventsub/subscriptions', array_filter(['after' => $cursor]))
                ->throw();

            array_push($subscriptions, ...(array) $response->json('data', []));
            $cursor = $response->json('pagination.cursor');
            if (! $cursor) {
                break;
            }
        }

        return $subscriptions;
    }

    // Queue -------------------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default');
        $checks = [];

        // Look at the database queue whatever the default: jobs stranded there
        // after a switch to Redis are the classic "worker on the wrong connection".
        $ready = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->selectRaw('queue, count(*) as jobs, min(available_at) as oldest')
            ->groupBy('queue')
            ->get();

        $count = (int) $ready->sum('jobs');
        $oldest = $ready->min('oldest');
        $age = $oldest === null ? 0 : now()->getTimestamp() - (int) $oldest;
        $byQueue = $ready->map(fn ($row) => "{$row->queue}: {$row->jobs}")->all();

        $name = 'Database queue backlog';
        if ($count === 0) {
            $checks[] = Check::ok($name, 'No jobs waiting in the database queue.');
        } elseif ($age > self::QUEUE_STALE_SECONDS) {
            $checks[] = Check::fail(
                $name,
                "{$count} job(s) waiting; the oldest for ".$this->ago($age).'. No worker is draining the database queue.',
                $connection === 'database'
                    ? 'Add a Forge daemon: php artisan queue:work database --queue='.self::WORKER_QUEUES.' (or move QUEUE_CONNECTION to redis and run Horizon).'
                    : "QUEUE_CONNECTION is {$connection}, so these were left behind. Drain them once: php artisan queue:work database --queue=".self::WORKER_QUEUES.' --stop-when-empty.',
                $byQueue,
            );
        } else {
            $checks[] = Check::ok($name, "{$count} job(s) waiting, the oldest for ".$this->ago($age).'.', $byQueue);
        }

        if ($connection === 'redis') {
            $checks[] = $this->horizon();
        }

        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $checks[] = $failed === 0
            ? Check::ok('Failed jobs (24 h)', 'No job failed in the last 24 hours.')
            : Check::warn('Failed jobs (24 h)', "{$failed} job(s) failed in the last 24 hours.", 'Inspect with php artisan queue:failed; retry with php artisan queue:retry all once fixed.');

        return $checks;
    }

    private function horizon(): Check
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            return Check::fail('Horizon', 'QUEUE_CONNECTION is redis, but Redis cannot be reached to ask Horizon.', 'Fix the Redis connection below first.');
        }

        return $masters === []
            ? Check::fail('Horizon', 'QUEUE_CONNECTION is redis but no Horizon supervisor is running.', 'Add a Forge daemon: php artisan horizon.')
            : Check::ok('Horizon', count($masters).' Horizon supervisor(s) running.');
    }

    // Redis -------------------------------------------------------------------

    public function redis(): Check
    {
        $name = 'Redis connection';
        $usedBy = array_keys(array_filter([
            'queue' => config('queue.default') === 'redis',
            'cache' => config('cache.default') === 'redis',
            'sessions' => config('session.driver') === 'redis',
            'broadcasting' => config('broadcasting.default') === 'redis',
        ]));

        $error = $this->probes->redisPing();
        $where = config('database.redis.default.host').':'.config('database.redis.default.port');
        $password = filled(config('database.redis.default.password')) ? 'REDIS_PASSWORD is set' : 'REDIS_PASSWORD is not set';

        if ($error === null) {
            return Check::ok($name, "PING answered at {$where} ({$password}).".($usedBy ? ' Used for: '.implode(', ', $usedBy).'.' : ''));
        }

        $status = $usedBy === [] ? Status::Warn : Status::Fail;
        $detail = $usedBy === [] ? ' Nothing uses Redis yet, but the queue move and Horizon will.' : ' Used for: '.implode(', ', $usedBy).'.';

        [$summary, $fix] = match (true) {
            str_contains($error, 'NOAUTH') || str_contains($error, 'WRONGPASS') || str_contains(strtolower($error), 'invalid password') => [
                "Redis at {$where} refused the credentials ({$password}).",
                'Set REDIS_PASSWORD to the server\'s requirepass (and REDIS_USERNAME if it uses ACLs), then php artisan optimize.',
            ],
            str_contains($error, 'Class "Redis" not found') || str_contains($error, "Class 'Redis' not found") => [
                'The phpredis extension is not installed.',
                'Install php-redis on the server, or set REDIS_CLIENT=predis.',
            ],
            default => [
                "Cannot reach Redis at {$where}.",
                'Check REDIS_HOST and REDIS_PORT, and that redis-server is running.',
            ],
        };

        return new Check($name, $status, $summary.$detail, $fix);
    }

    // Broadcasting ------------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function broadcasting(): array
    {
        $driver = (string) config('broadcasting.default');

        if ($driver !== 'reverb') {
            return [Check::warn(
                'Broadcast driver',
                "BROADCAST_CONNECTION is {$driver}, so live updates are off and overlays poll instead.",
                'Set BROADCAST_CONNECTION=reverb and the REVERB_* keys, enable Reverb in Forge, then npm run build and php artisan optimize.',
            )];
        }

        $checks = [Check::ok('Broadcast driver', 'BROADCAST_CONNECTION is reverb.')];

        $keys = [
            'REVERB_APP_ID' => config('broadcasting.connections.reverb.app_id'),
            'REVERB_APP_KEY' => config('broadcasting.connections.reverb.key'),
            'REVERB_APP_SECRET' => config('broadcasting.connections.reverb.secret'),
            'REVERB_HOST' => config('broadcasting.connections.reverb.options.host'),
        ];
        $unset = array_keys(array_filter($keys, fn ($value) => blank($value)));
        $checks[] = $unset === []
            ? Check::ok('Reverb keys', 'REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET and REVERB_HOST are set.')
            : Check::fail('Reverb keys', implode(', ', $unset).' not set.', 'Copy them from Forge\'s Reverb settings into .env, then php artisan optimize and npm run build.');

        $checks[] = $this->builtAssets((string) $keys['REVERB_APP_KEY']);

        $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
        $port = (int) config('reverb.servers.reverb.port', 8080);
        $probeHost = in_array($host, ['0.0.0.0', '::', ''], true) ? '127.0.0.1' : $host;
        $checks[] = $this->probes->tcpReachable($probeHost, $port)
            ? Check::ok('Reverb server', "Something is listening on {$probeHost}:{$port}.")
            : Check::fail('Reverb server', "Nothing answers on {$probeHost}:{$port}.", 'Turn on Reverb in Forge (it runs php artisan reverb:start under Supervisor) and check REVERB_SERVER_PORT.');

        return $checks;
    }

    /**
     * The browser learns the Reverb key at build time (VITE_REVERB_APP_KEY),
     * so a key set after the last `npm run build` never reaches overlays.
     */
    private function builtAssets(string $key): Check
    {
        $name = 'Built assets carry the Reverb key';
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return Check::fail($name, 'No built assets (public/build/manifest.json is missing).', 'Run npm ci && npm run build.');
        }

        if ($key === '') {
            return Check::skip($name, 'Waiting for REVERB_APP_KEY.');
        }

        $entries = (array) json_decode((string) file_get_contents($manifest), true);
        $files = collect(['resources/js/app.js', 'resources/js/overlay.js'])
            ->map(fn ($entry) => $entries[$entry]['file'] ?? null)
            ->filter()
            ->values();

        if ($files->isEmpty()) {
            return Check::fail($name, 'The manifest has no app or overlay script.', 'Run npm ci && npm run build.');
        }

        $stale = $files->reject(fn ($file) => str_contains((string) @file_get_contents(public_path('build/'.$file)), $key))->values()->all();

        return $stale === []
            ? Check::ok($name, 'The app and overlay scripts were built with the current REVERB_APP_KEY.')
            : Check::fail($name, 'Built before REVERB_APP_KEY was set (or changed): the browser cannot connect.', 'Run npm run build (the deploy script does) after setting VITE_REVERB_APP_KEY="${REVERB_APP_KEY}".', $stale);
    }

    // Scheduler ---------------------------------------------------------------

    public function scheduler(): Check
    {
        $name = 'Scheduler heartbeat';
        $last = SchedulerHeartbeat::last();
        $fix = 'Enable the Forge Scheduler (cron: * * * * * php artisan schedule:run).';

        if ($last === null) {
            return Check::fail($name, 'No heartbeat recorded: php artisan schedule:run has never run here.', $fix);
        }

        $age = (int) $last->diffInSeconds(now(), true);
        $summary = 'Last ran '.$this->ago($age).' ago.';

        return match (true) {
            $age <= self::HEARTBEAT_WARN_SECONDS => Check::ok($name, $summary),
            $age <= self::HEARTBEAT_FAIL_SECONDS => Check::warn($name, $summary.' It should run every minute.', $fix),
            default => Check::fail($name, $summary.' The scheduler has stopped.', $fix),
        };
    }

    // YouTube -----------------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function youtube(): array
    {
        $checks = [];
        $keySet = filled(config('services.youtube.api_key'));

        $checks[] = $keySet
            ? Check::ok('YouTube API key', 'YOUTUBE_API_KEY is set.')
            : Check::warn('YouTube API key', 'YOUTUBE_API_KEY is not set, so YouTube live chat is not read.', 'Create an API key in Google Cloud (YouTube Data API v3) and set YOUTUBE_API_KEY.');

        $channels = (array) config('services.youtube.channel_ids');
        $checks[] = match (true) {
            $channels !== [] => Check::ok('YouTube channels', count($channels).' channel(s) allowed.', $channels),
            $keySet => Check::fail('YouTube channels', 'YOUTUBE_CHANNEL_IDS is empty, so no YouTube chat is read and no channel can be connected.', 'Set YOUTUBE_CHANNEL_IDS to the show\'s channel ids (UC...), comma separated.'),
            default => Check::skip('YouTube channels', 'YouTube is not configured.', 'Set YOUTUBE_API_KEY and YOUTUBE_CHANNEL_IDS to read YouTube chat.'),
        };

        array_push($checks, ...$this->youtubeOAuth());

        $used = Quota::used(Quota::UNITS);
        $limit = Quota::limit(Quota::UNITS);
        $summary = "{$used} of {$limit} units used today (Pacific Time; resets ".Quota::resetsAt()->format('H:i').' '.config('app.timezone').').';
        $checks[] = match (true) {
            ! $keySet && $used === 0 => Check::skip('YouTube quota today', $summary),
            $used >= $limit => Check::fail('YouTube quota today', $summary.' Exhausted: chat stops until the reset.', 'Request a quota increase in Google Cloud, or poll less (YOUTUBE_POLL_FLOOR_MS).'),
            $used >= Quota::alertThreshold(Quota::UNITS) => Check::warn('YouTube quota today', $summary, 'Raise YOUTUBE_POLL_FLOOR_MS, or request a quota increase in Google Cloud.'),
            default => Check::ok('YouTube quota today', $summary),
        };

        return $checks;
    }

    /**
     * Google OAuth for channel owners (#126): posting chat replies needs
     * youtube.force-ssl, YouTube Analytics needs the analytics scopes.
     *
     * @return list<Check>
     */
    private function youtubeOAuth(): array
    {
        $settings = [
            'YOUTUBE_OAUTH_CLIENT_ID' => config('services.youtube.oauth.client_id'),
            'YOUTUBE_OAUTH_CLIENT_SECRET' => config('services.youtube.oauth.client_secret'),
            'YOUTUBE_OAUTH_REDIRECT_URL' => config('services.youtube.oauth.redirect'),
        ];
        $unset = array_keys(array_filter($settings, fn ($value) => blank($value)));
        $checks = [];

        $checks[] = $unset === []
            ? Check::ok('YouTube OAuth client', 'YOUTUBE_OAUTH_CLIENT_ID, YOUTUBE_OAUTH_CLIENT_SECRET and YOUTUBE_OAUTH_REDIRECT_URL are set.')
            : Check::warn('YouTube OAuth client', implode(', ', $unset).' not set, so channel owners cannot connect YouTube (needed for chat replies and analytics).', 'Create a Web OAuth client in Google Cloud, register '.route('youtube.broadcaster.callback').' as its redirect URI, and set the three YOUTUBE_OAUTH_* values.');

        $tokens = YouTubeChannelToken::orderBy('channel_id')->get();
        $configured = (array) config('services.youtube.channel_ids');
        $channelIds = collect($configured)->merge($tokens->pluck('channel_id'))->unique()->values();
        $connect = 'Sign in as a broadcaster and open '.route('youtube.broadcaster.connect').' with the channel\'s Google account.';

        foreach ($channelIds as $channelId) {
            $token = $tokens->firstWhere('channel_id', $channelId);
            $label = $token?->channel_title ? "{$token->channel_title} ({$channelId})" : $channelId;
            $name = "YouTube channel {$label}: connection and scopes";

            if ($token === null) {
                $checks[] = $unset === []
                    ? Check::warn($name, 'Not connected, so ARE cannot reply in its chat or read its analytics.', $connect)
                    : Check::skip($name, 'Needs the YouTube OAuth client first.');

                continue;
            }

            $missing = array_values(array_diff([YouTubeApi::POST_SCOPE, ...YouTubeApi::ANALYTICS_SCOPES], (array) $token->scopes));
            $short = array_map(fn ($scope) => str_replace('https://www.googleapis.com/auth/', '', $scope), $missing);
            $checks[] = match (true) {
                $missing === [] => Check::ok($name, 'Connected; can post chat replies and read analytics.'),
                ! $token->canPost() => Check::fail($name, 'Connected without youtube.force-ssl, so chat replies cannot be posted.', 'Reconnect and grant every permission. '.$connect, $short),
                default => Check::warn($name, 'Connected; chat replies work, but analytics scopes are missing.', 'Reconnect and grant every permission. '.$connect, $short),
            };
        }

        $repliesOn = (bool) config('chat.replies.enabled') && (bool) config('chat.replies.youtube');
        $canPost = $tokens->contains(fn (YouTubeChannelToken $token) => $token->canPost());
        $checks[] = match (true) {
            ! $repliesOn => Check::warn('YouTube chat replies', 'CHAT_REPLIES_YOUTUBE is off (or CHAT_REPLIES_ENABLED is), so ARE reads YouTube chat but never answers there.', 'Set CHAT_REPLIES_YOUTUBE=true once a channel is connected with youtube.force-ssl.'),
            ! $canPost => Check::fail('YouTube chat replies', 'CHAT_REPLIES_YOUTUBE is on, but no connected channel can post.', $connect),
            default => Check::ok('YouTube chat replies', 'CHAT_REPLIES_YOUTUBE is on, and a connected channel can post (each reply costs 50 quota units).'),
        };

        return $checks;
    }

    // Chat Control Bus --------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function controlBus(): array
    {
        $games = Game::all();
        if ($games === []) {
            return [Check::skip('Games', 'No game is configured in config/bus.php.')];
        }

        // Read only: BusControl::for() would create rows.
        $global = BusControl::find(BusControl::GLOBAL);
        $controls = BusControl::whereIn('scope', array_keys($games))->get()->keyBy('scope');
        $tokens = BusAdapterToken::whereIn('game', array_keys($games))->pluck('game')->all();

        $checks = [$global?->killed_at !== null
            ? Check::warn('Kill switch', 'ON since '.$global->killed_at->diffForHumans().': nothing is published to any game.', 'Reset it at '.route('bus').' (broadcaster) or run php artisan bus:kill --off.')
            : Check::ok('Kill switch', 'Off: the bus publishes.')];

        foreach ($games as $key => $game) {
            $control = $controls->get($key);
            $mode = ($control->mode ?? $game->defaultMode)->value;
            $active = $global?->active_game === $key ? ', the running game' : '';

            $checks[] = $control?->paused_at !== null
                ? Check::warn("{$game->label}: state", "Paused since {$control->paused_at->diffForHumans()} ({$mode}{$active}).", 'Resume it at '.route('bus').'.')
                : Check::ok("{$game->label}: state", "Running in {$mode} mode{$active}.");

            $checks[] = in_array($key, $tokens, true)
                ? Check::ok("{$game->label}: adapter token", 'Issued.')
                : Check::warn("{$game->label}: adapter token", 'Not issued. An adapter that polls '.route('bus.actions', $key).' (no Reverb) cannot connect.', "Run php artisan bus:token {$key} and give the token to the adapter.");
        }

        return $checks;
    }

    // Music player ------------------------------------------------------------

    public function musicPlayer(): Check
    {
        $players = MusicPlayerToken::orderBy('name')->get();

        if ($players->isEmpty()) {
            return Check::warn('Player tokens', 'No local player token is issued, so only moderators can advance the song request queue.', 'Run php artisan music:player-token obs (or another short name) and put the token in the player.');
        }

        return Check::ok(
            'Player tokens',
            $players->count().' player token(s) issued.',
            $players->map(fn (MusicPlayerToken $player) => $player->name.': '.($player->last_used_at ? 'last used '.$player->last_used_at->diffForHumans() : 'never used'))->all(),
        );
    }

    // Mail and leads ----------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function mail(): array
    {
        $mailer = (string) config('mail.default');
        $checks = [];

        $checks[] = in_array($mailer, ['log', 'array'], true)
            ? Check::warn('Mailer', "MAIL_MAILER is {$mailer}, so mail is written to the log, not sent.", 'Set MAIL_MAILER (for example smtp or postmark) and its credentials.')
            : Check::ok('Mailer', "MAIL_MAILER is {$mailer}.");

        $checks[] = filled(config('mail.from.address')) && config('mail.from.address') !== 'hello@example.com'
            ? Check::ok('Sender address', 'MAIL_FROM_ADDRESS is set.')
            : Check::warn('Sender address', 'MAIL_FROM_ADDRESS is not set to a real address.', 'Set MAIL_FROM_ADDRESS to an address your mail provider will send from.');

        $notify = (array) config('are.leads.notify');
        $webhook = filled(config('are.leads.webhook_url'));
        $checks[] = $notify !== []
            ? Check::ok('Lead notifications', count($notify).' address(es) in ARE_LEADS_NOTIFY; ARE_LEADS_WEBHOOK_URL is '.($webhook ? 'set' : 'not set').'.')
            : Check::warn('Lead notifications', 'ARE_LEADS_NOTIFY is not set: nobody is emailed about a new enquiry'.($webhook ? ' (the webhook is set).' : ' and no webhook is set.'), 'Set ARE_LEADS_NOTIFY to one or more addresses, comma separated.');

        return $checks;
    }

    // Deploy ------------------------------------------------------------------

    /**
     * @return list<Check>
     */
    public function deploy(): array
    {
        $checks = [];
        [$commit, $deployedAt] = $this->gitHead();

        $checks[] = $commit === null
            ? Check::warn('Deployed commit', 'Unknown: no .git checkout here.', null)
            : Check::ok('Deployed commit', substr($commit, 0, 12).($deployedAt ? ', checked out '.$deployedAt->diffForHumans().'.' : '.'));

        $production = app()->environment('production');
        $checks[] = $production && config('app.debug')
            ? Check::fail('Debug mode', 'APP_DEBUG is on in production: error pages show stack traces and config.', 'Set APP_DEBUG=false, then php artisan optimize.')
            : Check::ok('Debug mode', 'APP_ENV is '.app()->environment().', APP_DEBUG is '.(config('app.debug') ? 'on' : 'off').'.');

        $checks[] = $this->caches($deployedAt, $production);

        return $checks;
    }

    private function caches(?Carbon $deployedAt, bool $production): Check
    {
        $name = 'Config and route caches';
        $configCache = app()->getCachedConfigPath();

        if (! app()->configurationIsCached()) {
            return $production
                ? Check::warn($name, 'Config is not cached, so every request re-reads it.', 'Run php artisan optimize (the deploy script does).')
                : Check::ok($name, 'Not cached, which is normal outside production.');
        }

        $cachedAt = Carbon::createFromTimestamp((int) filemtime($configCache));
        $envPath = app()->environmentFilePath();
        $envChanged = is_file($envPath) ? Carbon::createFromTimestamp((int) filemtime($envPath)) : null;

        $stale = array_values(array_filter([
            $envChanged?->greaterThan($cachedAt) ? '.env changed after the config was cached' : null,
            $deployedAt?->greaterThan($cachedAt) ? 'code was deployed after the config was cached' : null,
            ! app()->routesAreCached() ? 'routes are not cached' : null,
        ]));

        return $stale === []
            ? Check::ok($name, 'Config and routes cached '.$cachedAt->diffForHumans().', after the last .env change and deploy.')
            : Check::warn($name, 'Caches may be stale: '.implode('; ', $stale).'.', 'Run php artisan optimize.');
    }

    /**
     * The checked-out commit and when it was checked out, read from .git
     * without running git. Handles a plain clone and a worktree (where .git
     * is a file pointing at the real git directory).
     *
     * @return array{0: string|null, 1: Carbon|null}
     */
    public function gitHead(?string $root = null): array
    {
        $git = ($root ?? base_path()).'/.git';
        $common = $git;

        if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m)) {
            $git = $this->resolvePath(trim($m[1]), dirname($git));
            $commonDir = @file_get_contents($git.'/commondir');
            $common = is_string($commonDir) ? $this->resolvePath(trim($commonDir), $git) : $git;
        }

        $head = @file_get_contents($git.'/HEAD');
        if (! is_string($head)) {
            return [null, null];
        }

        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return [preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null, Carbon::createFromTimestamp((int) filemtime($git.'/HEAD'))];
        }

        $ref = substr($head, 5);
        foreach ([$git, $common] as $dir) {
            if (is_file($dir.'/'.$ref)) {
                return [trim((string) file_get_contents($dir.'/'.$ref)), Carbon::createFromTimestamp((int) filemtime($dir.'/'.$ref))];
            }
        }

        foreach (explode("\n", (string) @file_get_contents($common.'/packed-refs')) as $line) {
            if (str_ends_with(trim($line), ' '.$ref)) {
                return [strtok($line, ' ') ?: null, null];
            }
        }

        return [null, null];
    }

    private function resolvePath(string $path, string $relativeTo): string
    {
        return str_starts_with($path, '/') ? $path : $relativeTo.'/'.$path;
    }

    // Error reporting ---------------------------------------------------------

    /**
     * Whether errors reach Sentry. config/sentry.php reads SENTRY_LARAVEL_DSN,
     * falling back to SENTRY_DSN. The DSN itself is never shown.
     */
    public function sentry(): Check
    {
        return filled(config('sentry.dsn'))
            ? Check::ok('Sentry', 'SENTRY_LARAVEL_DSN is set, so errors are reported to Sentry.')
            : Check::warn('Sentry', 'SENTRY_LARAVEL_DSN is not set, so errors only reach the log on the server.', 'Copy the DSN from the Sentry project\'s Client Keys page into SENTRY_LARAVEL_DSN, then php artisan optimize.');
    }

    // -------------------------------------------------------------------------

    /**
     * @param  Closure(): list<Check>  $checks
     * @return list<Check>
     */
    private function guard(string $group, Closure $checks): array
    {
        try {
            return $checks();
        } catch (Throwable $e) {
            report($e);

            // The class only: messages can carry hosts, URLs or tokens.
            return [Check::fail($group, 'This check could not run ('.class_basename($e).'). The error was reported.', 'See the application log for details.')];
        }
    }

    private function ago(int $seconds): string
    {
        return Carbon::now()->subSeconds($seconds)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);
    }
}
