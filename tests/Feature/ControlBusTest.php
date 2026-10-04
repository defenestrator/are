<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\ControlBus\BallotStatus;
use App\ControlBus\ControlBus;
use App\ControlBus\Mode;
use App\ControlBus\Picker;
use App\ControlBus\WindowStatus;
use App\Events\BusActionPublished;
use App\Events\BusActionVetoed;
use App\Events\BusStateChanged;
use App\IdentityProvider;
use App\Jobs\ResolveBusWindow;
use App\Models\BusAdapterToken;
use App\Models\BusBallot;
use App\Models\BusControl;
use App\Models\BusPublication;
use App\Models\BusWindow;
use App\Models\ModerationAction;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;

// The Chat Control Bus (#9). Chat goes through the real registry, so !do gets
// the same user resolution, ban and rate-limit rules as every chat command.

function busBus(): ControlBus
{
    return app(ControlBus::class);
}

function busModerator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function busBroadcaster(): User
{
    return User::factory()->twitch('1000')->create();
}

function busSubscribe(User $user, TwitchSubscription $tier = TwitchSubscription::Tier3): void
{
    UserTwitchSubscription::create(['user_id' => $user->id, 'broadcaster_id' => '1000', 'twitch_subscription' => $tier]);
}

/** Send a chat message as the user, through the registry. */
function busSay(User $user, string $text, IdentityProvider $provider = IdentityProvider::Twitch): ?ChatCommandResult
{
    $chatterId = $user->identities()->where('provider', $provider)->value('provider_user_id');

    return app(ChatCommandRegistry::class)->run($provider, '1000', (string) $chatterId, $user->name, (string) Str::uuid(), $text);
}

function busStart(Mode $mode = Mode::Democracy, string $game = 'orkestera'): User
{
    $mod = busModerator();
    busBus()->setActiveGame($mod, $game);
    if ($mode !== Mode::Democracy) {
        busBus()->setMode($mod, $game, $mode);
    }

    return $mod;
}

/** Let the open window run out and resolve it, as the delayed job would. */
function busCloseWindow(): ?BusPublication
{
    test()->travel(5)->minutes();

    return busBus()->resolve(BusWindow::open()->latest('id')->firstOrFail());
}

function busPage(): Testable
{
    return Livewire::test(FragmentAlias::encode('bus', resource_path('views/bus.blade.php')));
}

beforeEach(function () {
    config(['bus.platforms' => ['twitch']]);
});

// --- Chat into ballots ------------------------------------------------------

test('!do with no game running is refused and still audited', function () {
    $result = busSay(User::factory()->create(), '!do task Write the README');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toContain('No chat game')
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::NoGame);
});

test('!do opens a vote window sized to the slowest connected platform and schedules its close', function () {
    Queue::fake();
    config(['bus.platforms' => ['twitch', 'youtube'], 'bus.platform_latency_seconds.youtube' => 12, 'bus.games.orkestera.window_seconds' => 60]);
    busStart();

    $result = busSay(User::factory()->create(), '!do task Write the README');

    $window = BusWindow::sole();
    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toBe('Voted for #1: task Write the README.')
        ->and($window->mode)->toBe(Mode::Democracy)
        ->and((int) $window->opens_at->diffInSeconds($window->closes_at))->toBe(72);
    Queue::assertPushed(ResolveBusWindow::class, fn (ResolveBusWindow $job) => $job->windowId === $window->id && $job->queue === 'broadcasts');
});

test('actions are normalised: case and spacing do not split an option', function () {
    busStart();

    busSay(User::factory()->create(), '!do task Write the README');
    busSay(User::factory()->create(), '!do TASK   write  the readme');

    expect(BusBallot::where('status', BallotStatus::Counted)->orderBy('id')->pluck('option_number')->all())->toBe([1, 1])
        ->and(BusBallot::orderBy('id')->pluck('action_key')->unique()->all())->toBe(['task:write the readme']);
});

test('an action the game does not know is refused with its usage', function (string $text) {
    busStart();

    $result = busSay(User::factory()->create(), $text);

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::Invalid);
})->with(['!do', '!do jump', '!do task hi', '!do task '.str_repeat('x', 201), '!do #7']);

test('!do #N backs option N of the open vote', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    busSay(User::factory()->create(), '!do task Fix the tests');

    $result = busSay(User::factory()->create(), '!do #2');

    expect($result->reply)->toBe('Voted for #2: task Fix the tests.')
        ->and(BusBallot::where('option_number', 2)->where('status', BallotStatus::Counted)->count())->toBe(2);
});

// --- One person, one vote ---------------------------------------------------

test('voting again in a window replaces your vote, so each person counts once', function () {
    busStart();
    $viewer = User::factory()->create();

    busSay($viewer, '!do task Write the README');
    busSay($viewer, '!do task Fix the tests');

    expect(BusBallot::where('user_id', $viewer->id)->orderBy('id')->pluck('status')->all())->toBe([BallotStatus::Replaced, BallotStatus::Counted]);
});

test('one person on two platforms still has one vote', function () {
    busStart();
    $viewer = User::factory()->youtube('UC-viewer')->create();

    busSay($viewer, '!do task Write the README', IdentityProvider::Twitch);
    busSay($viewer, '!do task Write the README', IdentityProvider::YouTube);

    expect(BusBallot::where('status', BallotStatus::Counted)->count())->toBe(1)
        ->and(busCloseWindow()->votes)->toBe(1);
});

// --- Democracy and weighted random ------------------------------------------

test('democracy publishes the option with the most people behind it', function () {
    Event::fake([BusActionPublished::class]);
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    busSay(User::factory()->create(), '!do task Fix the tests');
    busSay(User::factory()->create(), '!do #2');

    $publication = busCloseWindow();

    expect($publication->only(['game', 'verb', 'argument', 'votes', 'total_votes']))
        ->toBe(['game' => 'orkestera', 'verb' => 'task', 'argument' => 'Fix the tests', 'votes' => 2, 'total_votes' => 3])
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Resolved);
    Event::assertDispatched(BusActionPublished::class, fn (BusActionPublished $e) => $e->publicationId === $publication->id && $e->game === 'orkestera');
});

test('a democracy tie goes to the option proposed first', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    busSay(User::factory()->create(), '!do task Fix the tests');

    expect(busCloseWindow()->argument)->toBe('Write the README');
});

test('weighted random draws with odds equal to each option\'s headcount', function () {
    $picker = new class extends Picker
    {
        public array $weights = [];

        public function pick(array $weights): string
        {
            $this->weights = $weights;

            return array_key_last($weights);
        }
    };
    app()->instance(Picker::class, $picker);
    busStart(Mode::WeightedRandom);

    busSay(User::factory()->create(), '!do task Write the README');
    busSay(User::factory()->create(), '!do #1');
    busSay(User::factory()->create(), '!do task Fix the tests');

    $publication = busCloseWindow();

    expect($picker->weights)->toBe(['task:write the readme' => 2, 'task:fix the tests' => 1])
        ->and($publication->argument)->toBe('Fix the tests')
        ->and($publication->mode)->toBe(Mode::WeightedRandom);
});

test('the default picker only ever returns a backed option', function () {
    $picker = new Picker;

    foreach (range(1, 50) as $i) {
        expect($picker->pick(['a' => 1, 'b' => 3]))->toBeIn(['a', 'b']);
    }
    expect($picker->pick(['only' => 2]))->toBe('only');
});

test('an empty window publishes nothing', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    BusBallot::query()->update(['status' => BallotStatus::Vetoed]);

    expect(busCloseWindow())->toBeNull()
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Empty);
});

test('resolving is idempotent, and bus:resolve closes due windows', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $this->travel(5)->minutes();

    $this->artisan('bus:resolve')->expectsOutputToContain('Closed 1 window')->assertSuccessful();
    busBus()->resolve(BusWindow::sole());
    $this->artisan('bus:resolve')->assertSuccessful();

    expect(BusPublication::count())->toBe(1);
});

test('the delayed job resolves its window', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $this->travel(5)->minutes();

    (new ResolveBusWindow(BusWindow::sole()->id))->handle(busBus());

    expect(BusPublication::sole()->argument)->toBe('Write the README');
});

test('a ballot arriving after the window closed resolves it and opens the next', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $this->travel(5)->minutes();

    busSay(User::factory()->create(), '!do task Fix the tests');

    expect(BusWindow::orderBy('id')->pluck('status')->all())->toBe([WindowStatus::Resolved, WindowStatus::Open])
        ->and(BusPublication::sole()->argument)->toBe('Write the README')
        ->and(BusBallot::latest('id')->first()->option_number)->toBe(1);
});

// --- Subscribers: cosmetic perks only ---------------------------------------

test('a subscriber\'s vote weighs exactly what anyone else\'s does', function () {
    busStart();
    $sub = User::factory()->create();
    busSubscribe($sub, TwitchSubscription::Tier3);

    busSay($sub, '!do task Subscriber idea');
    busSay(User::factory()->create(), '!do task Viewer idea');
    busSay(User::factory()->create(), '!do #2');

    $publication = busCloseWindow();

    expect($publication->argument)->toBe('Viewer idea')
        ->and($publication->votes)->toBe(2)
        ->and($publication->total_votes)->toBe(3);
});

test('in weighted random a subscriber adds one to the odds, like everyone', function () {
    $picker = new class extends Picker
    {
        public array $weights = [];

        public function pick(array $weights): string
        {
            $this->weights = $weights;

            return array_key_first($weights);
        }
    };
    app()->instance(Picker::class, $picker);
    busStart(Mode::WeightedRandom);
    $sub = User::factory()->create();
    busSubscribe($sub, TwitchSubscription::Tier3);

    busSay($sub, '!do task Subscriber idea');
    busSay(User::factory()->create(), '!do task Viewer idea');
    busCloseWindow();

    expect($picker->weights)->toBe(['task:subscriber idea' => 1, 'task:viewer idea' => 1]);
});

test('a subscriber\'s proposal carries cosmetic flair when it wins, and nothing else changes', function () {
    busStart();
    $sub = User::factory()->create();
    busSubscribe($sub);

    busSay($sub, '!do task Subscriber idea');

    $publication = busCloseWindow();
    expect(BusBallot::sole()->subscriber)->toBeTrue()
        ->and($publication->flair)->toBe(ControlBus::FLAIR_SUBSCRIBER)
        ->and($publication->votes)->toBe(1)
        ->and($publication->payload()['flair'])->toBe('subscriber');
});

// --- Anarchy ----------------------------------------------------------------

test('anarchy publishes every action at once, rate-limited per person', function () {
    Event::fake([BusActionPublished::class]);
    config(['bus.games.orkestera.anarchy' => ['actions' => 2, 'per_seconds' => 60]]);
    busStart(Mode::Anarchy);
    $eager = User::factory()->create();

    $results = collect(range(1, 3))->map(fn ($i) => busSay($eager, "!do task Idea number {$i}"));
    $other = busSay(User::factory()->create(), '!do task Someone else');

    expect($results->pluck('status')->all())->toBe([ChatCommandStatus::Done, ChatCommandStatus::Done, ChatCommandStatus::Rejected])
        ->and($other->status)->toBe(ChatCommandStatus::Done)
        ->and(BusPublication::orderBy('id')->pluck('argument')->all())->toBe(['Idea number 1', 'Idea number 2', 'Someone else'])
        ->and(BusPublication::first()->mode)->toBe(Mode::Anarchy)
        ->and(BusBallot::orderBy('id')->pluck('status')->all())->toBe([BallotStatus::Published, BallotStatus::Published, BallotStatus::RateLimited, BallotStatus::Published])
        ->and(BusWindow::count())->toBe(0);
    Event::assertDispatchedTimes(BusActionPublished::class, 3);
});

test('anarchy has no options to back', function () {
    busStart(Mode::Anarchy);

    expect(busSay(User::factory()->create(), '!do #1')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::Invalid);
});

// --- Pause, kill switch, veto -----------------------------------------------

test('a paused game refuses actions, and a window that closes while paused publishes nothing', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');

    busBus()->pause($mod, 'orkestera');
    $refused = busSay(User::factory()->create(), '!do task Fix the tests');

    expect($refused->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusBallot::latest('id')->first()->status)->toBe(BallotStatus::Paused)
        ->and(busCloseWindow())->toBeNull()
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Held);

    busBus()->resume($mod, 'orkestera');
    busSay(User::factory()->create(), '!do task Fix the tests');
    expect(busCloseWindow()->argument)->toBe('Fix the tests')
        ->and(ModerationAction::orderBy('id')->pluck('action')->all())->toBe(['bus.game', 'bus.paused', 'bus.resumed']);
});

test('the kill switch stops every publish, and only a broadcaster can reset it', function () {
    $mod = busStart(Mode::Anarchy);

    busBus()->kill($mod, 'testing');
    $refused = busSay(User::factory()->create(), '!do task Write the README');

    expect($refused->reply)->toContain('stopped')
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::Killed)
        ->and(BusPublication::count())->toBe(0)
        ->and(fn () => busBus()->restore($mod))->toThrow(AuthorizationException::class);

    busBus()->restore(busBroadcaster());
    busSay(User::factory()->create(), '!do task Write the README');

    expect(BusPublication::count())->toBe(1)
        ->and(ModerationAction::orderBy('id')->pluck('action')->all())->toBe(['bus.game', 'bus.mode', 'bus.killed', 'bus.restored'])
        ->and(ModerationAction::where('action', 'bus.killed')->first()->details)->toBe(['reason' => 'testing']);
});

test('a window that closes while the bus is killed publishes nothing', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    busBus()->kill($mod);

    expect(busCloseWindow())->toBeNull()
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Killed);
});

test('the kill switch is read fresh on every publish, never cached', function () {
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do task First action');

    // Thrown by another process: straight into the database, past ControlBus.
    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]);
    busSay(User::factory()->create(), '!do task Second action');

    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => null]);
    busSay(User::factory()->create(), '!do task Third action');

    expect(BusPublication::orderBy('id')->pluck('argument')->all())->toBe(['First action', 'Third action']);
});

test('a pause thrown by another process is read fresh too', function () {
    busStart(Mode::Anarchy);

    BusControl::whereKey('orkestera')->update(['paused_at' => now()]);

    expect(busSay(User::factory()->create(), '!do task An action')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusPublication::count())->toBe(0);
});

test('BUS_ENABLED=false is a deploy-time kill switch', function () {
    busStart(Mode::Anarchy);
    config(['bus.enabled' => false]);

    busSay(User::factory()->create(), '!do task An action');

    expect(BusBallot::sole()->status)->toBe(BallotStatus::Killed)
        ->and(BusPublication::count())->toBe(0);
});

test('a queued broadcast checks the switches again when it is sent', function () {
    Event::fake([BusActionPublished::class]);
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do task An action');
    $event = new BusActionPublished('orkestera', BusPublication::sole()->id);

    expect($event->broadcastWhen())->toBeTrue()
        ->and(array_map(fn (Channel $c) => $c->name, $event->broadcastOn()))->toBe(['bus.orkestera']);

    // Laravel calls broadcastOn() inside the queued job; no channels, no send.
    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]);
    expect($event->broadcastOn())->toBe([])->and($event->broadcastWhen())->toBeFalse();

    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => null]);
    busBus()->pause($mod, 'orkestera');
    expect($event->broadcastOn())->toBe([]);

    busBus()->resume($mod, 'orkestera');
    busBus()->vetoPublication($mod, BusPublication::sole());
    expect($event->broadcastOn())->toBe([]);
});

test('vetoing an option drops its votes and stops anyone backing it again', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Bad idea');
    busSay(User::factory()->create(), '!do #1');
    busSay(User::factory()->create(), '!do task Good idea');

    $vetoed = busBus()->vetoOption($mod, BusWindow::sole(), 'task:bad idea');
    $again = busSay(User::factory()->create(), '!do task BAD IDEA');

    expect($vetoed)->toBe(2)
        ->and($again->reply)->toContain('vetoed')
        ->and(busCloseWindow()->argument)->toBe('Good idea')
        ->and(ModerationAction::where('action', 'bus.option_vetoed')->sole()->details['ballots'])->toBe(2);
});

test('vetoing a published action marks it and tells adapters to undo it', function () {
    Event::fake([BusActionVetoed::class]);
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do task An action');
    $publication = BusPublication::sole();

    busBus()->vetoPublication($mod, $publication);
    busBus()->vetoPublication($mod, $publication->fresh());

    expect($publication->fresh()->vetoed_at)->not->toBeNull()
        ->and($publication->fresh()->payload()['vetoed'])->toBeTrue()
        ->and(ModerationAction::where('action', 'bus.vetoed')->count())->toBe(1);
    Event::assertDispatchedTimes(BusActionVetoed::class, 1);
    Event::assertDispatched(BusActionVetoed::class, fn (BusActionVetoed $e) => $e->publicationId === $publication->id && $e->broadcastAs() === 'bus.veto');
});

test('viewers cannot control the bus', function () {
    $viewer = User::factory()->create();
    $window = BusWindow::create(['game' => 'orkestera', 'mode' => Mode::Democracy, 'status' => WindowStatus::Open, 'opens_at' => now(), 'closes_at' => now()->addMinute()]);

    foreach ([
        fn () => busBus()->setActiveGame($viewer, 'orkestera'),
        fn () => busBus()->setMode($viewer, 'orkestera', Mode::Anarchy),
        fn () => busBus()->pause($viewer, 'orkestera'),
        fn () => busBus()->kill($viewer),
        fn () => busBus()->vetoOption($viewer, $window, 'task:x'),
    ] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
});

test('changing the mode or the game cancels the open vote', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');

    busBus()->setMode($mod, 'orkestera', Mode::WeightedRandom);
    expect(BusWindow::sole()->status)->toBe(WindowStatus::Cancelled);

    busSay(User::factory()->create(), '!do task Fix the tests');
    busBus()->setActiveGame($mod, null);

    expect(BusWindow::orderBy('id')->pluck('status')->all())->toBe([WindowStatus::Cancelled, WindowStatus::Cancelled])
        ->and(busSay(User::factory()->create(), '!do task Anything')->reply)->toContain('No chat game');
});

test('every control change is announced on the game channel, even while killed', function () {
    Event::fake([BusStateChanged::class]);
    $mod = busStart();

    busBus()->kill($mod);

    Event::assertDispatched(BusStateChanged::class, fn (BusStateChanged $e) => $e->game === 'orkestera'
        && $e->killed && $e->running && $e->broadcastAs() === 'bus.state'
        && $e->broadcastWith() === ['game' => 'orkestera', 'killed' => true, 'paused' => false, 'mode' => 'democracy', 'running' => true]);
});

test('bus:kill is the operator\'s switch, without a moderator account', function () {
    busStart(Mode::Anarchy);

    $this->artisan('bus:kill')->assertSuccessful();
    busSay(User::factory()->create(), '!do task An action');
    expect(BusPublication::count())->toBe(0);

    $this->artisan('bus:kill', ['--off' => true])->assertSuccessful();
    busSay(User::factory()->create(), '!do task An action');
    expect(BusPublication::count())->toBe(1);
});

// --- What adapters receive --------------------------------------------------

test('a published action goes to bus.{game} as bus.action, on the broadcasts queue, with no user data', function () {
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(['name' => 'Secret Name']), '!do task An action');
    $event = new BusActionPublished('orkestera', BusPublication::sole()->id);

    expect($event->broadcastAs())->toBe('bus.action')
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and(array_keys($event->broadcastWith()))->toBe(['id', 'game', 'verb', 'argument', 'mode', 'votes', 'total_votes', 'window_id', 'flair', 'vetoed', 'published_at'])
        ->and(json_encode($event->broadcastWith()))->not->toContain('Secret Name');
});

test('adapters can poll for actions with their token', function () {
    $token = BusAdapterToken::issue('orkestera');
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do task First action');
    busSay(User::factory()->create(), '!do task Second action');
    $first = BusPublication::orderBy('id')->first();
    busBus()->vetoPublication($mod, $first);

    $response = $this->withToken($token)->getJson('/bus/orkestera/actions?after='.$first->id)->assertOk();

    expect($response->json('actions.*.argument'))->toBe(['Second action'])
        ->and($response->json('cursor'))->toBe($first->id + 1)
        ->and($response->json('vetoed'))->toBe([$first->id])
        ->and($response->json())->toMatchArray(['game' => 'orkestera', 'running' => true, 'killed' => false, 'paused' => false, 'mode' => 'anarchy']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->assertJsonCount(2, 'actions');
});

test('polling needs the game\'s token', function () {
    BusAdapterToken::issue('orkestera');

    $this->getJson('/bus/orkestera/actions')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/bus/orkestera/actions')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/bus/nonsense/actions')->assertNotFound();
});

test('while the bus is killed, polling returns no actions', function () {
    $token = BusAdapterToken::issue('orkestera');
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do task An action');
    busBus()->kill($mod);

    $this->withToken($token)->getJson('/bus/orkestera/actions')
        ->assertOk()
        ->assertJson(['killed' => true, 'actions' => []]);
});

test('bus:token prints a token once and refuses to replace it without --rotate', function () {
    $this->artisan('bus:token', ['game' => 'orkestera'])->expectsOutputToContain('Authorization: Bearer ')->assertSuccessful();
    $this->artisan('bus:token', ['game' => 'orkestera'])->assertFailed();
    $this->artisan('bus:token', ['game' => 'orkestera', '--rotate' => true])->assertSuccessful();
    $this->artisan('bus:token', ['game' => 'nope'])->assertExitCode(2);

    expect(BusAdapterToken::count())->toBe(1);
});

// --- The moderator page -----------------------------------------------------

test('the /bus page is for moderators', function () {
    $this->actingAs(User::factory()->create())->get('/bus')->assertForbidden();

    $this->actingAs(busModerator())->get('/bus')->assertOk()->assertSee('Chat Control Bus')->assertSee('Chat Plays Orkestera');
});

test('a moderator runs the bus from the page, and a viewer calling its actions gets a 403', function () {
    $mod = busModerator();
    $this->actingAs($mod);

    busPage()->call('setGame', 'orkestera')->call('pause', 'orkestera');
    expect(BusControl::find(BusControl::GLOBAL)->active_game)->toBe('orkestera')
        ->and(BusControl::find('orkestera')->paused_at)->not->toBeNull();

    busPage()->call('resume', 'orkestera')->call('setMode', 'orkestera', 'anarchy');
    busSay(User::factory()->create(), '!do task An action');
    busPage()->assertSee('task An action')->call('vetoPublication', BusPublication::sole()->id)->set('reason', 'raid')->call('kill');

    expect(BusPublication::sole()->vetoed_at)->not->toBeNull()
        ->and(BusControl::find(BusControl::GLOBAL)->killed_at)->not->toBeNull();
    busPage()->assertSee('Kill switch is on')->call('restore')->assertForbidden();

    $this->actingAs(User::factory()->create());
    foreach (['kill' => [], 'pause' => ['orkestera'], 'setGame' => [''], 'vetoPublication' => [BusPublication::sole()->id]] as $method => $args) {
        busPage()->call($method, ...$args)->assertForbidden();
    }
});

test('a broadcaster resets the kill switch from the page', function () {
    busBus()->kill(busModerator());
    $this->actingAs(busBroadcaster());

    busPage()->call('restore');

    expect(BusControl::find(BusControl::GLOBAL)->killed_at)->toBeNull();
});

test('the page shows the open vote with its options, and vetoes one', function () {
    $mod = busStart();
    $sub = User::factory()->create();
    busSubscribe($sub);
    busSay($sub, '!do task Write the README');
    busSay(User::factory()->create(), '!do task Fix the tests');
    $this->actingAs($mod);

    busPage()->assertSee('#1')->assertSee('task Write the README')->assertSee('sub')
        ->call('vetoOption', BusWindow::sole()->id, 'task:fix the tests');

    expect(BusBallot::where('action_key', 'task:fix the tests')->sole()->status)->toBe(BallotStatus::Vetoed);
});
