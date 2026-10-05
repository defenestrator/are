<?php

use App\Models\BroadcasterToken;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Readiness\Check;
use App\Readiness\Probes;
use App\Readiness\ReadinessChecks;
use App\Readiness\SchedulerHeartbeat;
use App\Readiness\Status;
use App\Twitch;
use App\YouTube\Quota;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Livewire\Volt\Volt;

const READY_SECRET = 'SENTINEL-SECRET-VALUE';

beforeEach(function () {
    config([
        'services.twitch.broadcaster_id' => '1000',
        'services.twitch.broadcaster_ids' => [],
        'services.twitch.client_id' => 'client-id',
        'services.twitch.client_secret' => READY_SECRET,
        'services.twitch.eventsub_secret' => READY_SECRET,
        'services.twitch.eventsub_callback' => 'https://are.example/twitch/eventsub',
    ]);
    Cache::put('twitch.app_access_token', 'app-token');

    // Socket checks are faked; each test sets what they answer.
    $this->probes = new class extends Probes
    {
        public ?string $redisError = null;

        public bool $reverbUp = true;

        public function redisPing(): ?string
        {
            return $this->redisError;
        }

        public function tcpReachable(string $host, int $port): bool
        {
            return $this->reverbUp;
        }
    };
    $this->app->instance(Probes::class, $this->probes);

    // Files the checks read or write live in a scratch directory, not the checkout.
    $this->scratch = sys_get_temp_dir().'/are-readiness-'.Str::random(8);
    File::makeDirectory($this->scratch.'/public/build/assets', 0755, true);
    File::makeDirectory($this->scratch.'/storage/framework', 0755, true);
    $this->app->usePublicPath($this->scratch.'/public');
    $this->app->useStoragePath($this->scratch.'/storage');
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

function readinessBroadcaster(): User
{
    return User::factory()->twitch('1000')->create();
}

/** @return array<string, list<Check>> */
function readinessGroups(): array
{
    return app(ReadinessChecks::class)->all();
}

function readinessCheck(string $name): Check
{
    return collect(readinessGroups())->flatten()->first(fn (Check $check) => $check->name === $name)
        ?? throw new RuntimeException("No check named {$name}");
}

/** Every expected EventSub subscription for channel 1000, as Helix lists them. */
function helixSubscriptions(array $overrides = []): array
{
    return collect(Twitch::EVENTSUB_TYPES)->map(function (string $type) use ($overrides) {
        [$version, $condition] = Twitch::eventSubDefinition($type, '1000');

        return array_merge([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'version' => $version,
            'status' => 'enabled',
            'condition' => $condition,
            'transport' => ['method' => 'webhook', 'callback' => 'https://are.example/twitch/eventsub'],
        ], $overrides[$type] ?? []);
    })->all();
}

function connectAllScopes(): void
{
    BroadcasterToken::create([
        'broadcaster_id' => '1000', 'access_token' => 'a', 'refresh_token' => 'r',
        'expires_at' => now()->addHour(), 'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);
}

// Access -----------------------------------------------------------------------

test('a broadcaster can open the readiness page and the checks render', function () {
    Http::fake(['api.twitch.tv/helix/eventsub/subscriptions*' => Http::response(['data' => helixSubscriptions()])]);
    connectAllScopes();
    $this->actingAs(readinessBroadcaster());

    $this->get('/admin/readiness')->assertOk()
        ->assertSee('Launch readiness')
        ->assertSee('Running the checks');   // the checks load lazily

    Volt::test('admin.readiness')
        ->assertSee('Twitch')->assertSee('Queue')->assertSee('Redis')->assertSee('Scheduler')
        ->assertSee('YouTube')->assertSee('Mail and leads')->assertSee('Deploy')
        ->assertSee('Check again');
});

test('moderators, viewers, guests and a banned broadcaster are refused', function () {
    $mod = User::factory()->twitch('77')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);
    $banned = readinessBroadcaster();
    $banned->localBans()->create(['moderator_id' => $mod->id]);

    $this->get('/admin/readiness')->assertForbidden();
    $this->actingAs($mod)->get('/admin/readiness')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/admin/readiness')->assertForbidden();
    $this->actingAs($banned)->get('/admin/readiness')->assertForbidden();
});

test('the component refuses a non-broadcaster even when reached directly', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('admin.readiness')->assertForbidden();
});

test('only broadcasters see the Readiness link', function () {
    $this->actingAs(readinessBroadcaster())->get('/vote')->assertSee(route('admin.readiness'), false);
    $this->actingAs(User::factory()->create())->get('/vote')->assertDontSee(route('admin.readiness'), false);
});

test('no secret value is ever shown', function () {
    config([
        'database.redis.default.password' => READY_SECRET,
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => READY_SECRET,
        'broadcasting.connections.reverb.key' => READY_SECRET,
        'broadcasting.connections.reverb.secret' => READY_SECRET,
        'broadcasting.connections.reverb.options.host' => 'ws.are.example',
        'services.youtube.api_key' => READY_SECRET,
        'are.leads.webhook_url' => 'https://hooks.example/'.READY_SECRET,
    ]);
    File::put($this->scratch.'/public/build/manifest.json', json_encode(['resources/js/app.js' => ['file' => 'assets/app.js']]));
    File::put($this->scratch.'/public/build/assets/app.js', 'key:"'.READY_SECRET.'"');
    $this->probes->redisError = 'RedisException: NOAUTH Authentication required. '.READY_SECRET;
    Http::fake(['*' => Http::response(['message' => READY_SECRET], 500)]);

    $this->actingAs(readinessBroadcaster());
    Volt::test('admin.readiness')->assertDontSee(READY_SECRET)->assertSee('REDIS_PASSWORD is set');
});

test('one check that throws does not hide the others or leak its message', function () {
    Http::fake(['api.twitch.tv/helix/eventsub/subscriptions*' => Http::response(['message' => 'secret detail '.READY_SECRET], 500)]);
    connectAllScopes();

    $groups = readinessGroups();
    $twitch = collect($groups['Twitch']);

    expect($twitch->pluck('status')->all())->toContain(Status::Fail)
        ->and($twitch->firstWhere('name', 'Twitch')->summary)->toContain('RequestException')
        ->and(json_encode($groups))->not->toContain(READY_SECRET)
        ->and(array_keys($groups))->toContain('Queue', 'Redis', 'Scheduler', 'Deploy');
});

// Twitch -----------------------------------------------------------------------

test('a channel that was never connected is red, with a reconnect link', function () {
    Http::fake(['*' => Http::response(['data' => helixSubscriptions()])]);

    $check = readinessCheck('Channel 1000: connection and scopes');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->fix)->toContain(route('twitch.broadcaster.connect'));
});

test('missing broadcaster scopes are listed', function () {
    Http::fake(['*' => Http::response(['data' => helixSubscriptions()])]);
    BroadcasterToken::create([
        'broadcaster_id' => '1000', 'access_token' => 'a', 'refresh_token' => 'r',
        'expires_at' => now()->addHour(), 'scopes' => ['moderation:read', 'channel:moderate'],
    ]);

    $check = readinessCheck('Channel 1000: connection and scopes');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->details)->toContain('user:write:chat', 'channel:read:redemptions')
        ->and($check->details)->not->toContain('moderation:read');
});

test('a connection with every scope is green', function () {
    Http::fake(['*' => Http::response(['data' => helixSubscriptions()])]);
    connectAllScopes();

    expect(readinessCheck('Channel 1000: connection and scopes')->status)->toBe(Status::Ok);
});

test('missing Twitch app credentials are red', function () {
    config(['services.twitch.client_secret' => null]);

    expect(readinessCheck('Twitch app credentials')->status)->toBe(Status::Fail)
        ->and(readinessCheck('EventSub subscriptions')->status)->toBe(Status::Skip);
});

// EventSub -----------------------------------------------------------------------

test('all expected EventSub subscriptions enabled is green', function () {
    Http::fake(['api.twitch.tv/helix/eventsub/subscriptions*' => Http::response(['data' => helixSubscriptions()])]);

    $check = readinessCheck('EventSub subscriptions');

    expect($check->status)->toBe(Status::Ok)
        ->and($check->summary)->toContain('All '.count(Twitch::EVENTSUB_TYPES));
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer app-token') && $request->hasHeader('Client-ID', 'client-id'));
});

test('missing and failed EventSub subscriptions are listed with the command to fix them', function () {
    $subs = collect(helixSubscriptions(['channel.raid' => ['status' => 'webhook_callback_verification_failed']]))
        ->reject(fn ($sub) => $sub['type'] === 'channel.follow')->values()->all();
    Http::fake(['api.twitch.tv/helix/eventsub/subscriptions*' => Http::response(['data' => $subs])]);

    $check = readinessCheck('EventSub subscriptions');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->fix)->toContain('php artisan twitch:eventsub-subscribe')
        ->and($check->details)->toContain('missing 1000: channel.follow', 'not enabled 1000: channel.raid (webhook_callback_verification_failed)');
});

test('a subscription pointing at another callback does not count', function () {
    Http::fake(['*' => Http::response(['data' => helixSubscriptions(['stream.online' => ['transport' => ['method' => 'webhook', 'callback' => 'https://old.example/hook']]])])]);

    expect(readinessCheck('EventSub subscriptions')->details)->toContain('missing 1000: stream.online');
});

test('EventSub pagination is followed', function () {
    [$first, $second] = array_chunk(helixSubscriptions(), 6);
    Http::fake(['api.twitch.tv/helix/eventsub/subscriptions*' => Http::sequence()
        ->push(['data' => $first, 'pagination' => ['cursor' => 'next-page']])
        ->push(['data' => $second, 'pagination' => []])]);

    expect(readinessCheck('EventSub subscriptions')->status)->toBe(Status::Ok);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=next-page'));
});

test('an EventSub callback that is not HTTPS is red without asking Helix', function () {
    Http::fake();
    config(['services.twitch.eventsub_callback' => 'http://localhost/twitch/eventsub']);

    expect(readinessCheck('EventSub subscriptions')->status)->toBe(Status::Fail);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'eventsub'));
});

test('a missing EventSub secret is red', function () {
    config(['services.twitch.eventsub_secret' => null]);
    Http::fake();

    expect(readinessCheck('EventSub subscriptions')->fix)->toContain('twitch:generate-event-sub-key');
});

// Queue ------------------------------------------------------------------------------

function queueJob(int $ageSeconds, string $queue = 'default'): void
{
    DB::table('jobs')->insert([
        'queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->getTimestamp() - $ageSeconds, 'created_at' => now()->getTimestamp() - $ageSeconds,
    ]);
}

test('an old database job means no worker is draining the queue', function () {
    config(['queue.default' => 'database']);
    queueJob(600);
    queueJob(10, 'broadcasts');

    $check = readinessCheck('Database queue backlog');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->summary)->toContain('No worker is draining the database queue')
        ->and($check->fix)->toContain('queue:work database')
        ->and($check->details)->toContain('default: 1', 'broadcasts: 1');
});

test('a fresh backlog, or an empty queue, is green; delayed jobs do not count', function () {
    config(['queue.default' => 'database']);
    expect(readinessCheck('Database queue backlog')->status)->toBe(Status::Ok);

    queueJob(30);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->getTimestamp() + 3600, 'created_at' => now()->getTimestamp() - 3600]);

    expect(readinessCheck('Database queue backlog')->status)->toBe(Status::Ok);
});

test('jobs stranded in the database after a move to Redis are red, and a missing Horizon too', function () {
    config(['queue.default' => 'redis']);
    queueJob(600);
    $horizon = Mockery::mock(MasterSupervisorRepository::class);
    $horizon->shouldReceive('all')->andReturn([]);
    $this->app->instance(MasterSupervisorRepository::class, $horizon);

    expect(readinessCheck('Database queue backlog')->fix)->toContain('--stop-when-empty')
        ->and(readinessCheck('Horizon')->status)->toBe(Status::Fail);
});

test('failed jobs in the last day are amber', function () {
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()->subHour()]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'old', 'failed_at' => now()->subDays(3)]);

    $check = readinessCheck('Failed jobs (24 h)');

    expect($check->status)->toBe(Status::Warn)->and($check->summary)->toContain('1 job(s)');
});

// Redis --------------------------------------------------------------------------------

test('Redis answering PING is green', function () {
    expect(readinessCheck('Redis connection')->status)->toBe(Status::Ok);
});

test('Redis refusing the credentials says to set REDIS_PASSWORD', function () {
    config(['queue.default' => 'redis']);
    $this->probes->redisError = 'RedisException: NOAUTH Authentication required.';

    $check = readinessCheck('Redis connection');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->fix)->toContain('REDIS_PASSWORD')
        ->and($check->summary)->toContain('REDIS_PASSWORD is not set');
});

test('Redis that nothing uses yet is amber, not red, when unreachable', function () {
    config(['queue.default' => 'database', 'cache.default' => 'database', 'session.driver' => 'database', 'broadcasting.default' => 'reverb']);
    $this->probes->redisError = 'RedisException: Connection refused';

    $check = readinessCheck('Redis connection');

    expect($check->status)->toBe(Status::Warn)->and($check->fix)->toContain('REDIS_HOST');
});

// Broadcasting -------------------------------------------------------------------------

function reverbConfigured(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'app',
        'broadcasting.connections.reverb.key' => 'reverb-key-123',
        'broadcasting.connections.reverb.secret' => 'shh',
        'broadcasting.connections.reverb.options.host' => 'ws.are.example',
    ]);
}

function buildAssets(string $appJs, string $overlayJs): void
{
    File::put(public_path('build/manifest.json'), json_encode([
        'resources/js/app.js' => ['file' => 'assets/app.js'],
        'resources/js/overlay.js' => ['file' => 'assets/overlay.js'],
    ]));
    File::put(public_path('build/assets/app.js'), $appJs);
    File::put(public_path('build/assets/overlay.js'), $overlayJs);
}

test('broadcasting off is amber, with how to turn it on', function () {
    config(['broadcasting.default' => 'null']);

    $check = readinessCheck('Broadcast driver');

    expect($check->status)->toBe(Status::Warn)->and($check->fix)->toContain('BROADCAST_CONNECTION=reverb');
});

test('missing Reverb keys are listed by name', function () {
    reverbConfigured();
    config(['broadcasting.connections.reverb.secret' => null, 'broadcasting.connections.reverb.options.host' => '']);

    $check = readinessCheck('Reverb keys');

    expect($check->status)->toBe(Status::Fail)->and($check->summary)->toContain('REVERB_APP_SECRET, REVERB_HOST');
});

test('built assets must carry the current Reverb key', function () {
    reverbConfigured();

    expect(readinessCheck('Built assets carry the Reverb key')->fix)->toContain('npm ci && npm run build');

    buildAssets('a("reverb-key-123")', 'b("reverb-key-123")');
    expect(readinessCheck('Built assets carry the Reverb key')->status)->toBe(Status::Ok);

    buildAssets('a("reverb-key-123")', 'b("old-key")');
    $check = readinessCheck('Built assets carry the Reverb key');
    expect($check->status)->toBe(Status::Fail)->and($check->details)->toBe(['assets/overlay.js']);
});

test('a Reverb server that does not answer is red', function () {
    reverbConfigured();
    $this->probes->reverbUp = false;

    expect(readinessCheck('Reverb server')->status)->toBe(Status::Fail);
});

// Scheduler ----------------------------------------------------------------------------

test('the scheduler heartbeat is scheduled every minute and writes the heartbeat', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'scheduler-heartbeat');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');

    $event->run(app());
    expect(SchedulerHeartbeat::last()?->diffInSeconds(now(), true))->toBeLessThan(5);
});

test('the scheduler check follows the heartbeat\'s age', function () {
    expect(readinessCheck('Scheduler heartbeat')->status)->toBe(Status::Fail);

    SchedulerHeartbeat::beat();
    expect(readinessCheck('Scheduler heartbeat')->status)->toBe(Status::Ok);

    $this->travel(5)->minutes();
    expect(readinessCheck('Scheduler heartbeat')->status)->toBe(Status::Warn);

    $this->travel(20)->minutes();
    $check = readinessCheck('Scheduler heartbeat');
    expect($check->status)->toBe(Status::Fail)->and($check->fix)->toContain('schedule:run');
});

// YouTube ------------------------------------------------------------------------------

test('YouTube without an API key is amber and its quota not applicable', function () {
    config(['services.youtube.api_key' => null]);

    expect(readinessCheck('YouTube API key')->status)->toBe(Status::Warn)
        ->and(readinessCheck('YouTube quota today')->status)->toBe(Status::Skip)
        ->and(readinessCheck('YouTube OAuth client and connected channels')->status)->toBe(Status::Skip);
});

test('YouTube quota turns amber at the alert ratio and red when spent', function () {
    config(['services.youtube.api_key' => 'k', 'services.youtube.quota.daily_units' => 100, 'services.youtube.quota.alert_ratio' => 0.8]);
    $spend = fn (int $units) => DB::table('youtube_quota_usage')->updateOrInsert(
        ['day' => Quota::day(), 'bucket' => Quota::UNITS],
        ['used' => $units, 'calls' => $units, 'failed_calls' => 0, 'created_at' => now(), 'updated_at' => now()],
    );

    $spend(10);
    expect(readinessCheck('YouTube quota today')->status)->toBe(Status::Ok);
    $spend(85);
    expect(readinessCheck('YouTube quota today')->status)->toBe(Status::Warn);
    $spend(100);
    expect(readinessCheck('YouTube quota today')->status)->toBe(Status::Fail)
        ->and(readinessCheck('YouTube quota today')->summary)->toContain('100 of 100 units');
});

// Mail and leads -------------------------------------------------------------------------

test('a log mailer and no lead recipients are amber', function () {
    config(['mail.default' => 'log', 'are.leads.notify' => [], 'are.leads.webhook_url' => null]);

    expect(readinessCheck('Mailer')->status)->toBe(Status::Warn)
        ->and(readinessCheck('Lead notifications')->fix)->toContain('ARE_LEADS_NOTIFY');

    config(['mail.default' => 'smtp', 'are.leads.notify' => ['ops@example.com']]);
    expect(readinessCheck('Mailer')->status)->toBe(Status::Ok)
        ->and(readinessCheck('Lead notifications')->summary)->toContain('1 address(es)');
});

// Deploy -------------------------------------------------------------------------------

test('debug mode in production is red', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['app.debug' => true]);

    expect(readinessCheck('Debug mode')->status)->toBe(Status::Fail);
});

test('the deployed commit is read from .git, in a plain clone and a worktree', function () {
    $sha = str_repeat('a1', 20);
    $clone = $this->scratch.'/clone';
    File::makeDirectory($clone.'/.git/refs/heads', 0755, true);
    File::put($clone.'/.git/HEAD', "ref: refs/heads/main\n");
    File::put($clone.'/.git/refs/heads/main', $sha."\n");

    expect(app(ReadinessChecks::class)->gitHead($clone)[0])->toBe($sha);

    $worktree = $this->scratch.'/worktree';
    File::makeDirectory($worktree, 0755, true);
    File::makeDirectory($clone.'/.git/worktrees/wt', 0755, true);
    File::put($worktree.'/.git', 'gitdir: '.$clone."/.git/worktrees/wt\n");
    File::put($clone.'/.git/worktrees/wt/HEAD', "ref: refs/heads/main\n");
    File::put($clone.'/.git/worktrees/wt/commondir', "../..\n");

    expect(app(ReadinessChecks::class)->gitHead($worktree)[0])->toBe($sha);

    File::delete($clone.'/.git/refs/heads/main');
    File::put($clone.'/.git/packed-refs', "# pack-refs\n{$sha} refs/heads/main\n");
    expect(app(ReadinessChecks::class)->gitHead($clone)[0])->toBe($sha)
        ->and(app(ReadinessChecks::class)->gitHead($this->scratch.'/nowhere'))->toBe([null, null]);
});

test('the overall verdict is the worst check', function () {
    expect(ReadinessChecks::worst(['a' => [Check::ok('x', 'y'), Check::warn('x', 'y', null)]]))->toBe(Status::Warn)
        ->and(ReadinessChecks::worst(['a' => [Check::ok('x', 'y')], 'b' => [Check::fail('x', 'y', null)]]))->toBe(Status::Fail)
        ->and(ReadinessChecks::worst(['a' => [Check::skip('x', 'y'), Check::ok('x', 'y')]]))->toBe(Status::Ok);
});
