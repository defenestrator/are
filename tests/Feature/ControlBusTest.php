<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Chat\Commands\DoAction;
use App\ControlBus\Action;
use App\ControlBus\ApprovalStatus;
use App\ControlBus\BallotStatus;
use App\ControlBus\ControlBus;
use App\ControlBus\Mode;
use App\ControlBus\Picker;
use App\ControlBus\Submission;
use App\ControlBus\WindowStatus;
use App\Events\BusActionPublished;
use App\Events\BusActionVetoed;
use App\Events\BusStateChanged;
use App\Events\BusTallyChanged;
use App\IdentityProvider;
use App\Jobs\PostChatReply;
use App\Jobs\ResolveBusWindow;
use App\Models\BusAdapterToken;
use App\Models\BusApproval;
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
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\DB;
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
function busCloseWindow(): BusPublication|BusApproval|null
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
    // Orkestera's task is free text, so it waits for moderator approval
    // (tested below). The mechanics run on `say`: free text with approval
    // switched off, which a harmless verb may do.
    config(['bus.games.orkestera.verbs.say' => ['argument' => 'text', 'min' => 5, 'max' => 200, 'approval' => false]]);
});

// --- Chat into ballots ------------------------------------------------------

test('!do with no game running is refused and still audited', function () {
    $result = busSay(User::factory()->create(), '!do say Write the README');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toContain('No chat game')
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::NoGame);
});

test('!do opens a vote window sized to the slowest connected platform and schedules its close', function () {
    Queue::fake();
    config(['bus.platforms' => ['twitch', 'youtube'], 'bus.platform_latency_seconds.youtube' => 12, 'bus.games.orkestera.window_seconds' => 60]);
    busStart();

    $result = busSay(User::factory()->create(), '!do say Write the README');

    $window = BusWindow::sole();
    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toBe('', 'a vote is not answered in chat')
        ->and($window->mode)->toBe(Mode::Democracy)
        ->and((int) $window->opens_at->diffInSeconds($window->closes_at))->toBe(72);
    Queue::assertPushed(ResolveBusWindow::class, fn (ResolveBusWindow $job) => $job->windowId === $window->id && $job->queue === 'broadcasts');
});

test('actions are normalised: case and spacing do not split an option', function () {
    busStart();

    busSay(User::factory()->create(), '!do say Write the README');
    busSay(User::factory()->create(), '!do SAY   write  the readme');

    expect(BusBallot::where('status', BallotStatus::Counted)->orderBy('id')->pluck('option_number')->all())->toBe([1, 1])
        ->and(BusBallot::orderBy('id')->pluck('action_key')->unique()->all())->toBe(['say:writethereadme']);
});

test('an action the game does not know is refused with its usage', function (string $text) {
    busStart();

    $result = busSay(User::factory()->create(), $text);

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::Invalid);
})->with(['!do', '!do jump', '!do say hi', '!do say '.str_repeat('x', 201), '!do #7']);

test('!do #N backs option N of the open vote', function () {
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    busSay(User::factory()->create(), '!do say Fix the tests');

    $result = busSay(User::factory()->create(), '!do #2');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and(BusBallot::latest('id')->first()->only(['option_number', 'argument']))->toBe(['option_number' => 2, 'argument' => 'Fix the tests'])
        ->and(BusBallot::where('option_number', 2)->where('status', BallotStatus::Counted)->count())->toBe(2);
});

test('chat hears back only about refusals: votes and rate limits get no reply', function () {
    config(['bus.games.orkestera.anarchy' => ['actions' => 1, 'per_seconds' => 60]]);
    busStart(Mode::Anarchy);
    $viewer = User::factory()->create();

    $sent = busSay($viewer, '!do say First idea');
    $limited = busSay($viewer, '!do say Second idea');
    $invalid = busSay(User::factory()->create(), '!do jump');

    expect([$sent->status, $sent->reply])->toBe([ChatCommandStatus::Done, ''])
        ->and([$limited->status, $limited->reply])->toBe([ChatCommandStatus::Rejected, ''])
        ->and($invalid->reply)->toBe('Try !do task <text>, !do say <text>.');
});

// --- One person, one vote ---------------------------------------------------

test('voting again in a window replaces your vote, so each person counts once', function () {
    busStart();
    $viewer = User::factory()->create();

    busSay($viewer, '!do say Write the README');
    busSay($viewer, '!do say Fix the tests');

    expect(BusBallot::where('user_id', $viewer->id)->orderBy('id')->pluck('status')->all())->toBe([BallotStatus::Replaced, BallotStatus::Counted]);
});

test('one person on two platforms still has one vote', function () {
    busStart();
    $viewer = User::factory()->youtube('UC-viewer')->create();

    busSay($viewer, '!do say Write the README', IdentityProvider::Twitch);
    busSay($viewer, '!do say Write the README', IdentityProvider::YouTube);

    expect(BusBallot::where('status', BallotStatus::Counted)->count())->toBe(1)
        ->and(busCloseWindow()->votes)->toBe(1);
});

// --- Democracy and weighted random ------------------------------------------

test('democracy publishes the option with the most people behind it', function () {
    Event::fake([BusActionPublished::class]);
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    busSay(User::factory()->create(), '!do say Fix the tests');
    busSay(User::factory()->create(), '!do #2');

    $publication = busCloseWindow();

    expect($publication->only(['game', 'verb', 'argument', 'votes', 'total_votes']))
        ->toBe(['game' => 'orkestera', 'verb' => 'say', 'argument' => 'Fix the tests', 'votes' => 2, 'total_votes' => 3])
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Resolved);
    Event::assertDispatched(BusActionPublished::class, fn (BusActionPublished $e) => $e->publicationId === $publication->id && $e->game === 'orkestera');
});

test('a democracy tie goes to the option proposed first', function () {
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    busSay(User::factory()->create(), '!do say Fix the tests');

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

    busSay(User::factory()->create(), '!do say Write the README');
    busSay(User::factory()->create(), '!do #1');
    busSay(User::factory()->create(), '!do say Fix the tests');

    $publication = busCloseWindow();

    expect($picker->weights)->toBe(['say:writethereadme' => 2, 'say:fixthetests' => 1])
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
    busSay(User::factory()->create(), '!do say Write the README');
    BusBallot::query()->update(['status' => BallotStatus::Vetoed]);

    expect(busCloseWindow())->toBeNull()
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Empty);
});

test('resolving is idempotent, and bus:resolve closes due windows', function () {
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    $this->travel(5)->minutes();

    $this->artisan('bus:resolve')->expectsOutputToContain('Closed 1 window')->assertSuccessful();
    busBus()->resolve(BusWindow::sole());
    $this->artisan('bus:resolve')->assertSuccessful();

    expect(BusPublication::count())->toBe(1);
});

test('the delayed job resolves its window', function () {
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    $this->travel(5)->minutes();

    (new ResolveBusWindow(BusWindow::sole()->id))->handle(busBus());

    expect(BusPublication::sole()->argument)->toBe('Write the README');
});

test('a ballot arriving after the window closed resolves it and opens the next', function () {
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    $this->travel(5)->minutes();

    busSay(User::factory()->create(), '!do say Fix the tests');

    expect(BusWindow::orderBy('id')->pluck('status')->all())->toBe([WindowStatus::Resolved, WindowStatus::Open])
        ->and(BusPublication::sole()->argument)->toBe('Write the README')
        ->and(BusBallot::latest('id')->first()->option_number)->toBe(1);
});

// --- Subscribers: cosmetic perks only ---------------------------------------

test('a subscriber\'s vote weighs exactly what anyone else\'s does', function () {
    busStart();
    $sub = User::factory()->create();
    busSubscribe($sub, TwitchSubscription::Tier3);

    busSay($sub, '!do say Subscriber idea');
    busSay(User::factory()->create(), '!do say Viewer idea');
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

    busSay($sub, '!do say Subscriber idea');
    busSay(User::factory()->create(), '!do say Viewer idea');
    busCloseWindow();

    expect($picker->weights)->toBe(['say:subscriberidea' => 1, 'say:vieweridea' => 1]);
});

test('a subscriber\'s proposal carries cosmetic flair when it wins, and nothing else changes', function () {
    busStart();
    $sub = User::factory()->create();
    busSubscribe($sub);

    busSay($sub, '!do say Subscriber idea');

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

    $results = collect(range(1, 3))->map(fn ($i) => busSay($eager, "!do say Idea number {$i}"));
    $other = busSay(User::factory()->create(), '!do say Someone else');

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
    busSay(User::factory()->create(), '!do say Write the README');

    busBus()->pause($mod, 'orkestera');
    $refused = busSay(User::factory()->create(), '!do say Fix the tests');

    expect($refused->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusBallot::latest('id')->first()->status)->toBe(BallotStatus::Paused)
        ->and(busCloseWindow())->toBeNull()
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Held);

    busBus()->resume($mod, 'orkestera');
    busSay(User::factory()->create(), '!do say Fix the tests');
    expect(busCloseWindow()->argument)->toBe('Fix the tests')
        ->and(ModerationAction::orderBy('id')->pluck('action')->all())->toBe(['bus.game', 'bus.paused', 'bus.resumed']);
});

test('the kill switch stops every publish, and only a broadcaster can reset it', function () {
    $mod = busStart(Mode::Anarchy);

    busBus()->kill($mod, 'testing');
    $refused = busSay(User::factory()->create(), '!do say Write the README');

    expect($refused->reply)->toContain('stopped')
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::Killed)
        ->and(BusPublication::count())->toBe(0)
        ->and(fn () => busBus()->restore($mod))->toThrow(AuthorizationException::class);

    busBus()->restore(busBroadcaster());
    busSay(User::factory()->create(), '!do say Write the README');

    expect(BusPublication::count())->toBe(1)
        ->and(ModerationAction::orderBy('id')->pluck('action')->all())->toBe(['bus.game', 'bus.mode', 'bus.killed', 'bus.restored'])
        ->and(ModerationAction::where('action', 'bus.killed')->first()->details)->toBe(['reason' => 'testing', 'voided' => ['publications' => 0, 'approvals' => 0, 'windows' => 0]]);
});

test('the kill switch cancels the open vote, so nothing from it is ever published', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do say Write the README');

    busBus()->kill($mod);
    busBus()->restore(busBroadcaster());
    $this->travel(5)->minutes();
    $this->artisan('bus:resolve')->assertSuccessful();

    expect(BusWindow::sole()->status)->toBe(WindowStatus::Cancelled)
        ->and(BusPublication::count())->toBe(0);
});

test('a window that closes while killed by another process publishes nothing', function () {
    busStart();
    busSay(User::factory()->create(), '!do say Write the README');
    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]);

    expect(busCloseWindow())->toBeNull()
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Killed);
});

test('the kill switch is read fresh on every publish, never cached', function () {
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say First action');

    // Thrown by another process: straight into the database, past ControlBus.
    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]);
    busSay(User::factory()->create(), '!do say Second action');

    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => null]);
    busSay(User::factory()->create(), '!do say Third action');

    expect(BusPublication::orderBy('id')->pluck('argument')->all())->toBe(['First action', 'Third action']);
});

test('a pause thrown by another process is read fresh too', function () {
    busStart(Mode::Anarchy);

    BusControl::whereKey('orkestera')->update(['paused_at' => now()]);

    expect(busSay(User::factory()->create(), '!do say An action')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(BusPublication::count())->toBe(0);
});

test('BUS_ENABLED=false is a deploy-time kill switch', function () {
    busStart(Mode::Anarchy);
    config(['bus.enabled' => false]);

    busSay(User::factory()->create(), '!do say An action');

    expect(BusBallot::sole()->status)->toBe(BallotStatus::Killed)
        ->and(BusPublication::count())->toBe(0);
});

test('a queued broadcast checks the switches again when it is sent', function () {
    config(['broadcasting.default' => 'reverb']);
    Event::fake([BusActionPublished::class, BusActionVetoed::class, BusStateChanged::class, BusTallyChanged::class]);
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say An action');
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
    busSay(User::factory()->create(), '!do say Bad idea');
    busSay(User::factory()->create(), '!do #1');
    busSay(User::factory()->create(), '!do say Good idea');

    $vetoed = busBus()->vetoOption($mod, BusWindow::sole(), 'say:badidea');
    $again = busSay(User::factory()->create(), '!do say BAD IDEA');

    expect($vetoed)->toBe(2)
        ->and($again->reply)->toContain('vetoed')
        ->and(busCloseWindow()->argument)->toBe('Good idea')
        ->and(ModerationAction::where('action', 'bus.option_vetoed')->sole()->details['ballots'])->toBe(2);
});

test('vetoing a published action marks it and tells adapters to undo it', function () {
    Event::fake([BusActionVetoed::class]);
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say An action');
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
        fn () => busBus()->vetoOption($viewer, $window, 'say:x'),
    ] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
});

test('changing the mode or the game cancels the open vote', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do say Write the README');

    busBus()->setMode($mod, 'orkestera', Mode::WeightedRandom);
    expect(BusWindow::sole()->status)->toBe(WindowStatus::Cancelled);

    busSay(User::factory()->create(), '!do say Fix the tests');
    busBus()->setActiveGame($mod, null);

    expect(BusWindow::orderBy('id')->pluck('status')->all())->toBe([WindowStatus::Cancelled, WindowStatus::Cancelled])
        ->and(busSay(User::factory()->create(), '!do say Anything')->reply)->toContain('No chat game');
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
    busSay(User::factory()->create(), '!do say An action');
    expect(BusPublication::count())->toBe(0);

    $this->artisan('bus:kill', ['--off' => true])->assertSuccessful();
    busSay(User::factory()->create(), '!do say An action');
    expect(BusPublication::count())->toBe(1);
});

// --- What adapters receive --------------------------------------------------

test('a published action goes to bus.{game} as bus.action, on the broadcasts queue, with no user data', function () {
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(['name' => 'Secret Name']), '!do say An action');
    $event = new BusActionPublished('orkestera', BusPublication::sole()->id);

    expect($event->broadcastAs())->toBe('bus.action')
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and(array_keys($event->broadcastWith()))->toBe(['id', 'game', 'verb', 'argument', 'mode', 'votes', 'total_votes', 'window_id', 'flair', 'vetoed', 'published_at'])
        ->and(json_encode($event->broadcastWith()))->not->toContain('Secret Name');
});

test('with broadcasting off (production until Reverb), nothing is queued for Reverb and adapters poll instead', function () {
    config(['broadcasting.default' => 'log']);
    Queue::fake();
    $token = BusAdapterToken::issue('orkestera');
    busStart(Mode::Anarchy);

    busSay(User::factory()->create(), '!do say An action');

    Queue::assertNotPushed(BroadcastEvent::class);
    $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->assertJsonPath('actions.0.argument', 'An action');
});

test('with Reverb configured, a published action queues its broadcast on the broadcasts queue', function () {
    config(['broadcasting.default' => 'reverb']);
    Queue::fake();
    busStart(Mode::Anarchy);

    busSay(User::factory()->create(), '!do say An action');

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof BusActionPublished);
});

test('adapters can poll for actions with their token', function () {
    $token = BusAdapterToken::issue('orkestera');
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say First action');
    busSay(User::factory()->create(), '!do say Second action');
    $first = BusPublication::orderBy('id')->first();
    busBus()->vetoPublication($mod, $first);

    $response = $this->withToken($token)->getJson('/bus/orkestera/actions?after='.$first->id)->assertOk();

    expect($response->json('actions.*.argument'))->toBe(['Second action'])
        ->and($response->json('cursor'))->toBe($first->id + 1)
        ->and($response->json('vetoed'))->toBe([$first->id])
        ->and($response->json())->toMatchArray(['game' => 'orkestera', 'running' => true, 'killed' => false, 'paused' => false, 'mode' => 'anarchy']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // From the start: the vetoed action is left out of `actions`, listed in
    // `vetoed`, and the cursor still moves past it.
    $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')
        ->assertJsonCount(1, 'actions')
        ->assertJsonPath('actions.0.argument', 'Second action')
        ->assertJsonPath('cursor', $first->id + 1);
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
    busSay(User::factory()->create(), '!do say An action');
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
    busSay(User::factory()->create(), '!do say An action');
    busPage()->assertSee('say An action')->call('vetoPublication', BusPublication::sole()->id)->set('reason', 'raid')->call('kill');

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
    busSay($sub, '!do say Write the README');
    busSay(User::factory()->create(), '!do say Fix the tests');
    $this->actingAs($mod);

    busPage()->assertSee('#1')->assertSee('say Write the README')->assertSee('sub')
        ->call('vetoOption', BusWindow::sole()->id, 'say:fixthetests');

    expect(BusBallot::where('action_key', 'say:fixthetests')->sole()->status)->toBe(BallotStatus::Vetoed);
});

// --- Review round 1 (#123): the kill switch cancels ---------------------------

test('Andras A123-1: an action withheld by the kill switch is not delivered to polling adapters after restore', function () {
    Event::fake([BusActionVetoed::class]);
    $mod = busStart(Mode::Anarchy);
    $token = BusAdapterToken::issue('orkestera');

    busSay(User::factory()->create(), '!do say Delete the production database');   // published, not polled yet
    busBus()->kill($mod, 'bad action');
    busBus()->restore(busBroadcaster());

    $response = $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->assertOk();
    $publication = BusPublication::sole();

    expect($response->json('actions'))->toBeEmpty()
        ->and($response->json('vetoed'))->toBe([$publication->id])
        ->and($publication->veto_reason)->toBe('kill');
    Event::assertDispatched(BusActionVetoed::class, fn (BusActionVetoed $e) => $e->publicationId === $publication->id);
});

test('the kill voids what adapters may still run: undelivered or recently delivered, not long-done actions', function () {
    Event::fake([BusActionVetoed::class]);
    config(['bus.kill_undo_seconds' => 300]);
    $mod = busStart(Mode::Anarchy);
    $token = BusAdapterToken::issue('orkestera');

    busSay(User::factory()->create(), '!do say Long since done');
    $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->assertJsonCount(1, 'actions');   // delivered
    $this->travel(10)->minutes();

    busSay(User::factory()->create(), '!do say Just delivered');
    $cursor = $this->withToken($token)->getJson('/bus/orkestera/actions?after=1')->json('cursor');
    busSay(User::factory()->create(), '!do say Never delivered');

    busBus()->kill($mod);

    expect(BusPublication::orderBy('id')->get()->map(fn ($p) => [$p->argument, $p->veto_reason])->all())->toBe([
        ['Long since done', null],
        ['Just delivered', 'kill'],
        ['Never delivered', 'kill'],
    ]);
    Event::assertDispatchedTimes(BusActionVetoed::class, 2);

    busBus()->restore(busBroadcaster());
    expect($this->withToken($token)->getJson('/bus/orkestera/actions?after='.$cursor)->json('actions'))->toBeEmpty();
});

test('a Reverb send marks the action delivered', function () {
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say An action');
    $publication = BusPublication::sole();
    expect($publication->delivered_at)->toBeNull();

    (new BusActionPublished('orkestera', $publication->id))->broadcastWith();

    expect($publication->fresh()->delivered_at)->not->toBeNull();
});

test('publishing holds the kill switch row FOR SHARE and the kill takes it FOR UPDATE, so they serialize', function () {
    busStart(Mode::Anarchy);
    $pgsql = DB::getDriverName() === 'pgsql';
    $globalRow = fn (array $q) => str_starts_with($q['query'], 'select * from "bus_controls" where "bus_controls"."scope" = ?') && $q['bindings'] === ['*'];

    DB::flushQueryLog();
    DB::enableQueryLog();
    busSay(User::factory()->create(), '!do say An action');
    $publish = collect(DB::getQueryLog());

    DB::flushQueryLog();
    busBus()->kill(busModerator());
    $kill = collect(DB::getQueryLog());

    // The shared read comes before the publication is written.
    $shared = $publish->search(fn ($q) => $globalRow($q) && (! $pgsql || str_ends_with($q['query'], 'for share')));
    $insert = $publish->search(fn ($q) => str_starts_with($q['query'], 'insert into "bus_publications"'));
    expect($shared)->not->toBeFalse()->and($shared)->toBeLessThan($insert);

    if ($pgsql) {
        expect($kill->contains(fn ($q) => $globalRow($q) && str_ends_with($q['query'], 'for update')))->toBeTrue();
    }
});

test('the kill switch cancels approvals still waiting', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    busCloseWindow();

    busBus()->kill($mod);

    expect(BusApproval::sole()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Cancelled)
        ->and(fn () => busBus()->approve($mod, BusApproval::sole()))->toThrow(InvalidArgumentException::class);
});

test('Andras A123-3: kill and restore from the CLI leave an audit record', function () {
    $this->artisan('bus:kill')->assertSuccessful();
    $this->artisan('bus:kill', ['--off' => true])->assertSuccessful();

    $records = ModerationAction::whereIn('action', ['bus.killed', 'bus.restored'])->orderBy('id')->get();
    expect($records->pluck('action')->all())->toBe(['bus.killed', 'bus.restored'])
        ->and($records->pluck('moderator_id')->all())->toBe([null, null])
        ->and($records[0]->details)->toMatchArray(['via' => 'cli', 'command' => 'bus:kill', 'reason' => 'bus:kill'])
        ->and($records[1]->details)->toBe(['via' => 'cli', 'command' => 'bus:kill --off']);
});

// --- Review round 1 (#123): free text needs a moderator ------------------------

test('a free-text winner waits for a moderator, and only approval publishes it', function () {
    Event::fake([BusActionPublished::class]);
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    busSay(User::factory()->create(), '!do #1');

    $approval = busCloseWindow();

    expect($approval)->toBeInstanceOf(BusApproval::class)
        ->and($approval->only(['verb', 'argument', 'votes', 'total_votes']))->toBe(['verb' => 'task', 'argument' => 'Write the README', 'votes' => 2, 'total_votes' => 2])
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::AwaitingApproval)
        ->and(BusPublication::count())->toBe(0);
    Event::assertNotDispatched(BusActionPublished::class);

    $publication = busBus()->approve($mod, $approval);

    expect($publication->argument)->toBe('Write the README')
        ->and($approval->fresh()->only(['status', 'publication_id', 'decided_by_id']))->toBe(['status' => ApprovalStatus::Approved, 'publication_id' => $publication->id, 'decided_by_id' => $mod->id])
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Resolved)
        ->and(ModerationAction::where('action', 'bus.approved')->count())->toBe(1);
    Event::assertDispatched(BusActionPublished::class, fn (BusActionPublished $e) => $e->publicationId === $publication->id);
});

test('an approved action takes its place in the cursor order when it is approved, so polling never skips it', function () {
    $token = BusAdapterToken::issue('orkestera');
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $approval = busCloseWindow();

    // Meanwhile an adapter polls and moves its cursor on.
    busBus()->setMode($mod, 'orkestera', Mode::Anarchy);
    busSay(User::factory()->create(), '!do say Something else');
    $cursor = $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->json('cursor');

    busBus()->approve($mod, $approval);

    expect($this->withToken($token)->getJson('/bus/orkestera/actions?after='.$cursor)->json('actions.*.argument'))->toBe(['Write the README']);
});

test('a rejected free-text winner is never published, and its backers count as vetoed', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Delete the repo');
    $approval = busCloseWindow();

    busBus()->reject($mod, $approval, 'nope');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Rejected)
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::Vetoed)
        ->and(BusPublication::count())->toBe(0)
        ->and(ModerationAction::where('action', 'bus.rejected')->sole()->details)->toMatchArray(['reason' => 'nope'])
        ->and(fn () => busBus()->approve($mod, $approval->fresh()))->toThrow(InvalidArgumentException::class);
});

test('an approval nobody decides times out to rejected', function () {
    config(['bus.approval_timeout_seconds' => 60]);
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $approval = busCloseWindow();

    $this->travel(61)->seconds();
    expect(fn () => busBus()->approve($mod, $approval))->toThrow(InvalidArgumentException::class, 'timed out');

    expect($approval->fresh()->only(['status', 'reason']))->toBe(['status' => ApprovalStatus::Rejected, 'reason' => 'timed out'])
        ->and(BusPublication::count())->toBe(0);
});

test('bus:resolve rejects approvals that time out', function () {
    config(['bus.approval_timeout_seconds' => 60]);
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $approval = busCloseWindow();

    $this->travel(61)->seconds();
    $this->artisan('bus:resolve')->assertSuccessful();

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and(BusWindow::sole()->status)->toBe(WindowStatus::Rejected);
});

test('approving while paused or killed publishes nothing', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $approval = busCloseWindow();

    busBus()->pause($mod, 'orkestera');
    expect(fn () => busBus()->approve($mod, $approval))->toThrow(InvalidArgumentException::class, 'paused');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(BusPublication::count())->toBe(0);
});

test('in anarchy each free-text action waits for approval on its own', function () {
    $mod = busStart(Mode::Anarchy);
    $result = busSay(User::factory()->create(), '!do task Write the README');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and(BusBallot::sole()->status)->toBe(BallotStatus::PendingApproval)
        ->and(BusPublication::count())->toBe(0);

    busBus()->approve($mod, BusApproval::sole());

    expect(BusBallot::sole()->status)->toBe(BallotStatus::Published)
        ->and(BusPublication::sole()->mode)->toBe(Mode::Anarchy);
});

test('fixed verbs keep auto-publishing', function () {
    config(['bus.games.orkestera.verbs.lane' => ['argument' => 'choice', 'options' => ['left', 'right']]]);
    busStart();
    busSay(User::factory()->create(), '!do lane left');

    expect(busCloseWindow())->toBeInstanceOf(BusPublication::class)
        ->and(BusApproval::count())->toBe(0);
});

test('viewers cannot approve or reject', function () {
    busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $approval = busCloseWindow();
    $viewer = User::factory()->create();

    expect(fn () => busBus()->approve($viewer, $approval))->toThrow(AuthorizationException::class)
        ->and(fn () => busBus()->reject($viewer, $approval))->toThrow(AuthorizationException::class);
});

test('moderators approve and reject from /bus', function () {
    config(['bus.approval_timeout_seconds' => 3600]);
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $first = busCloseWindow();
    busSay(User::factory()->create(), '!do task Delete the repo');
    $second = busCloseWindow();
    $this->actingAs($mod);

    busPage()->assertSee('Waiting for approval')->assertSee('task Write the README')
        ->call('approve', $first->id)
        ->call('reject', $second->id);

    expect($first->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($second->fresh()->status)->toBe(ApprovalStatus::Rejected);

    $this->actingAs(User::factory()->create());
    busPage()->call('approve', $first->id)->assertForbidden();
});

test('an approval the page cannot publish shows why', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Write the README');
    $approval = busCloseWindow();
    busBus()->pause($mod, 'orkestera');
    $this->actingAs($mod);

    busPage()->call('approve', $approval->id)->assertHasErrors('approval');
});

// --- Review round 1 (#123): a veto cannot be dodged ----------------------------

test('Andras A123-2: a vetoed option cannot come back with a trailing full stop', function () {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Delete the repo');
    $window = BusWindow::open()->firstOrFail();
    busBus()->vetoOption($mod, $window, 'task:deletetherepo');

    busSay(User::factory()->create(), '!do task Delete the repo.');

    expect($window->ballots()->where('status', BallotStatus::Counted)->count())->toBe(0);
});

test('retyping a vetoed option any of these ways still matches it', function (string $retyped) {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Delete the repo');
    busBus()->vetoOption($mod, BusWindow::sole(), 'task:deletetherepo');

    $result = busSay(User::factory()->create(), '!do task '.$retyped);

    expect($result->reply)->toContain('vetoed')
        ->and(BusBallot::where('status', BallotStatus::Counted)->count())->toBe(0);
})->with([
    'punctuation' => 'Delete the repo!!!',
    'dash and spacing' => 'delete   the—repo',
    'Cyrillic look-alikes' => "D\u{0435}l\u{0435}te the r\u{0435}po",
    'full-width letters' => 'Ｄｅｌｅｔｅ the repo',
    'accents' => 'Délète the repo',
    'zero-width space' => "Del\u{200B}ete the repo",
]);

test('normalising keeps genuinely different actions apart', function () {
    expect(Action::normalise('Write the README'))->toBe('writethereadme')
        ->and(Action::normalise('Write the README twice'))->not->toBe(Action::normalise('Write the README'))
        ->and(Action::normalise('Fix bug 12'))->not->toBe(Action::normalise('Fix bug 13'));
});

test('backers of a vetoed option sit out the rest of the window', function () {
    $mod = busStart();
    $backer = User::factory()->create();
    busSay($backer, '!do task Delete the repo');
    busSay(User::factory()->create(), '!do task Write the README');
    busBus()->vetoOption($mod, BusWindow::sole(), 'task:deletetherepo');

    $again = busSay($backer, '!do task Remove every file');
    $other = busSay(User::factory()->create(), '!do #2');

    expect($again->reply)->toContain('sit out')
        ->and($other->status)->toBe(ChatCommandStatus::Done)
        ->and(BusBallot::where('user_id', $backer->id)->where('status', BallotStatus::Counted)->count())->toBe(0);
});

// --- Review round 2 (#123): no replay to an adapter that is behind ------------

test('Andras A123b-1: a second adapter that was offline does not run pre-kill actions after the restore', function () {
    config(['bus.kill_undo_seconds' => 300, 'bus.max_replay_seconds' => 3600]);
    $mod = busStart(Mode::Anarchy);
    $token = BusAdapterToken::issue('orkestera');

    busSay(User::factory()->create(), '!do say Drop all the tables');
    // Adapter A runs it; adapter B is offline with its cursor at 0.
    $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->assertJsonCount(1, 'actions');
    $this->travel(6)->minutes();

    busBus()->kill($mod, 'stop everything');
    busBus()->restore(busBroadcaster());

    expect($this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->json('actions'))->toBeEmpty()
        ->and(BusControl::find('orkestera')->replay_floor)->toBe(BusPublication::sole()->id);
});

test('actions published after the restore are served as normal', function () {
    $mod = busStart(Mode::Anarchy);
    $token = BusAdapterToken::issue('orkestera');
    busSay(User::factory()->create(), '!do say Before the kill');
    busBus()->kill($mod);
    busBus()->restore(busBroadcaster());

    busSay(User::factory()->create(), '!do say After the restore');

    expect($this->withToken($token)->getJson('/bus/orkestera/actions?after=0')->json('actions.*.argument'))->toBe(['After the restore']);
});

test('the Reverb send never carries an action from before the last kill', function () {
    config(['broadcasting.default' => 'reverb', 'bus.kill_undo_seconds' => 0]);
    Event::fake([BusActionPublished::class, BusActionVetoed::class, BusStateChanged::class, BusTallyChanged::class]);
    $mod = busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say Before the kill');
    $publication = BusPublication::sole();
    $publication->update(['delivered_at' => now()->subHour()]);   // long delivered, so the kill does not veto it

    busBus()->kill($mod);
    busBus()->restore(busBroadcaster());

    expect($publication->fresh()->vetoed_at)->toBeNull()
        ->and((new BusActionPublished('orkestera', $publication->id))->broadcastOn())->toBe([]);
});

test('a poll with no cursor starts from now', function () {
    $token = BusAdapterToken::issue('orkestera');
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say Old action');
    $newest = BusPublication::sole()->id;

    $this->withToken($token)->getJson('/bus/orkestera/actions')
        ->assertJsonCount(0, 'actions')
        ->assertJsonPath('cursor', $newest);

    busSay(User::factory()->create(), '!do say New action');
    $this->withToken($token)->getJson('/bus/orkestera/actions?after='.$newest)->assertJsonPath('actions.0.argument', 'New action');
});

test('a cursor older than the replay limit is clamped', function () {
    config(['bus.max_replay_seconds' => 300]);
    $token = BusAdapterToken::issue('orkestera');
    busStart(Mode::Anarchy);
    busSay(User::factory()->create(), '!do say Ancient action');
    $this->travel(10)->minutes();
    busSay(User::factory()->create(), '!do say Recent action');

    $this->withToken($token)->getJson('/bus/orkestera/actions?after=0')
        ->assertJsonPath('clamped', true)
        ->assertJsonCount(1, 'actions')
        ->assertJsonPath('actions.0.argument', 'Recent action');

    $this->withToken($token)->getJson('/bus/orkestera/actions?after='.BusPublication::min('id'))->assertJsonPath('clamped', false);
});

test('retyping inside words still matches a vetoed option', function (string $retyped) {
    $mod = busStart();
    busSay(User::factory()->create(), '!do task Delete the repo');
    busBus()->vetoOption($mod, BusWindow::sole(), 'task:deletetherepo');

    expect(busSay(User::factory()->create(), '!do task '.$retyped)->reply)->toContain('vetoed');
})->with([
    'hyphen inside a word' => 'De-lete the repo',
    'spaced-out letters' => 'd e l e t e the repo',
    'run together' => 'Deletetherepo',
    'Armenian look-alike o' => "Delete the rep\u{0585}",
]);

// --- #128: replies are fixed templates ----------------------------------------

test('no !do reply repeats the chatter\'s name, action, option or error text, on any path', function (Mode $mode) {
    $hostileName = 'FREE VBUCKS at scam.example';
    $hostile = 'Visit scam.example for FREE VBUCKS';
    config(['chat.commands_per_minute' => 1000, 'bus.games.orkestera.anarchy' => ['actions' => 1, 'per_seconds' => 60]]);
    $mod = busStart($mode);

    $say = function (User $user, string $text) use ($hostileName) {
        $chatterId = (string) $user->identities()->value('provider_user_id');

        return app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', $chatterId, $hostileName, (string) Str::uuid(), $text);
    };
    $viewer = User::factory()->create();
    $replies = [];

    // Accepted, then every refusal a viewer can reach.
    $replies[] = $say($viewer, "!do say {$hostile}");
    $replies[] = $say($viewer, "!do say {$hostile} again");           // anarchy: rate limited
    $replies[] = $say(User::factory()->create(), "!do {$hostile}");     // unknown verb
    $replies[] = $say(User::factory()->create(), '!do say '.str_repeat('vbucks ', 40)); // too long
    $replies[] = $say(User::factory()->create(), '!do say x');          // too short
    $replies[] = $say(User::factory()->create(), '!do #4242');          // no such option / anarchy reference
    if ($mode !== Mode::Anarchy) {
        busBus()->vetoOption($mod, BusWindow::sole(), "say:{$hostile}");
        $replies[] = $say(User::factory()->create(), "!do say {$hostile}"); // vetoed option
        $replies[] = $say($viewer, '!do say Something harmless');           // backer sits out
    }
    busBus()->pause($mod, 'orkestera');
    $replies[] = $say(User::factory()->create(), "!do say {$hostile}");
    busBus()->resume($mod, 'orkestera');
    busBus()->kill($mod);
    $replies[] = $say(User::factory()->create(), "!do say {$hostile}");

    foreach ($replies as $result) {
        foreach (['scam.example', 'vbucks', 'harmless', '4242'] as $marker) {
            expect(str_contains(mb_strtolower($result->reply), $marker))->toBeFalse("!do reply repeats chatter text: {$result->reply}");
        }
        expect(PostChatReply::echoesChatter($result->reply, $hostileName, $hostile))->toBeFalse();
    }
    expect(collect($replies)->pluck('reply')->filter()->count())->toBeGreaterThan(4);
})->with([Mode::Democracy, Mode::Anarchy]);

test('every !do refusal reason has a reply template, except a rate limit, which stays silent', function () {
    $reasons = collect((new ReflectionClass(Submission::class))->getConstants())->except(['ACCEPTED', 'RATE_LIMITED']);
    expect(DoAction::reply(new Submission(new BusBallot(['game' => 'orkestera']), Submission::RATE_LIMITED)))->toBe('');

    foreach ($reasons as $reason) {
        $ballot = new BusBallot(['game' => 'orkestera']);
        expect(DoAction::reply(new Submission($ballot, $reason)))->not->toBe('', $reason);
    }
    expect(DoAction::reply(new Submission(new BusBallot(['game' => 'orkestera']), Submission::INVALID)))->toBe('Try !do task <text>, !do say <text>.');
});
