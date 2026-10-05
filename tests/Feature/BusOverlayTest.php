<?php

use App\Chat\ChatCommandRegistry;
use App\ControlBus\BusOverlay;
use App\ControlBus\ControlBus;
use App\ControlBus\Game;
use App\ControlBus\Mode;
use App\Enums\Overlay;
use App\Events\BusTallyChanged;
use App\IdentityProvider;
use App\Jobs\BroadcastBusTally;
use App\Models\BusApproval;
use App\Models\BusWindow;
use App\Models\OverlayToken;
use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Volt\Volt;

// The on-stream Chat Control Bus overlay (#138). Chat goes through the real
// registry and ControlBus, as in ControlBusTest.

function overlayBus(): ControlBus
{
    return app(ControlBus::class);
}

function overlayBusModerator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function overlayBusSay(User $user, string $text): void
{
    $chatterId = $user->identities()->where('provider', IdentityProvider::Twitch)->value('provider_user_id');
    app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', (string) $chatterId, $user->name, (string) Str::uuid(), $text);
}

function overlayBusStart(Mode $mode = Mode::Democracy): User
{
    $mod = overlayBusModerator();
    overlayBus()->setActiveGame($mod, 'orkestera');
    if ($mode !== Mode::Democracy) {
        overlayBus()->setMode($mod, 'orkestera', $mode);
    }

    return $mod;
}

function overlayBusClose(): mixed
{
    test()->travel(5)->minutes();

    return overlayBus()->resolve(BusWindow::open()->latest('id')->firstOrFail());
}

function busOverlayPage(string $layout = 'horizontal'): TestResponse
{
    $token = OverlayToken::issue(Overlay::Bus);

    return test()->get(route('overlay.show', ['overlay' => 'bus', 'layout' => $layout, 'token' => $token]));
}

/** Every string anywhere in a nested array. */
function busStrings(mixed $value): array
{
    return is_array($value) ? array_merge(...array_map('busStrings', array_values($value)) ?: [[]]) : (is_string($value) ? [$value] : []);
}

beforeEach(function () {
    config([
        'bus.platforms' => ['twitch'],
        'broadcasting.default' => 'log',
        // A fixed verb, whose argument may always be shown...
        'bus.games.orkestera.verbs.move' => ['argument' => 'integer', 'min' => 1, 'max' => 9],
        // ...and free text without approval, which still may not be.
        'bus.games.orkestera.verbs.say' => ['argument' => 'text', 'min' => 5, 'max' => 200, 'approval' => false],
    ]);
});

// --- What the overlay shows -------------------------------------------------

test('with no chat game running the overlay shows nothing', function (string $layout) {
    busOverlayPage($layout)
        ->assertOk()
        ->assertViewIs('overlays.bus')
        ->assertSee('data-overlay-empty', false)
        ->assertSeeHtml('x-data="liveBus"')
        ->assertDontSee('data-bus-state', false);
})->with(['horizontal', 'vertical']);

test('an open vote shows each option, its count, the mode and the time left', function (string $layout) {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');
    overlayBusSay(User::factory()->create(), '!do #1');
    overlayBusSay(User::factory()->create(), '!do move 7');

    $html = busOverlayPage($layout)->assertOk()->assertSeeHtml('data-bus-state="running"')->getContent();
    // Without Livewire's <!--[if BLOCK]--> morph markers.
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    expect($html)->toContain('Chat Plays Orkestera')
        ->toContain('data-bus-mode')
        ->toContain('Democracy')
        ->toContain('data-countdown data-seconds-left=')
        ->toContain('data-option="1"')
        ->toContain('data-option="2"')
        ->toContain('!do #number');
    expect(preg_match('/data-option="1".*?data-option-votes[^>]*>2</s', $html))->toBe(1)
        ->and(preg_match('/data-option="2".*?data-option-votes[^>]*>1</s', $html))->toBe(1)
        // A fixed verb's argument is shown: "move" then "3".
        ->and($html)->toMatch('/data-option="1".*?>move<\/span>\s*3\s/s')
        ->and($html)->toMatch('/data-option="2".*?>move<\/span>\s*7\s/s');
})->with(['horizontal', 'vertical']);

test('free text is never shown before a moderator approves it, even for a verb without approval', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do task Delete the production database');
    overlayBusSay(User::factory()->create(), '!do say Something nobody vetted');

    $page = busOverlayPage()->assertOk();

    $page->assertDontSee('Delete the production database')
        ->assertDontSee('Something nobody vetted')
        ->assertSee('hidden until approved');
    $snapshot = BusOverlay::forOverlay();
    expect(json_encode($snapshot))->not->toContain('Delete the production')->not->toContain('nobody vetted')
        ->and(collect($snapshot['window']['options'])->pluck('label')->all())->toBe([null, null]);
});

test('a free-text winner shows as awaiting moderator approval, without its text', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do task Write the README');
    expect(overlayBusClose())->toBeInstanceOf(BusApproval::class);

    busOverlayPage()
        ->assertOk()
        ->assertSee('data-bus-awaiting', false)
        ->assertSee('Awaiting moderator approval')
        ->assertDontSee('Write the README');
});

test('once a moderator approves it, the winner\'s text is shown', function () {
    $mod = overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do task Write the README');
    $approval = overlayBusClose();

    overlayBus()->approve($mod, $approval);

    busOverlayPage()
        ->assertOk()
        ->assertSee('data-bus-result="published"', false)
        ->assertSee('Chat chose')
        ->assertSee('Write the README');
});

test('a rejected winner is reported without its text', function () {
    $mod = overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do task Write the README');
    $approval = overlayBusClose();

    overlayBus()->reject($mod, $approval);

    busOverlayPage()
        ->assertOk()
        ->assertSee('data-bus-result="rejected"', false)
        ->assertSee('turned down')
        ->assertDontSee('Write the README');
});

test('free text published without approval still never shows its text', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do say Something nobody vetted');
    overlayBusClose();

    busOverlayPage()
        ->assertOk()
        ->assertSee('data-bus-result="published"', false)
        ->assertDontSee('Something nobody vetted');
});

test('a fixed verb\'s published result shows its argument', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 4');
    overlayBusClose();

    expect(BusOverlay::forOverlay()['result'])->toMatchArray(['status' => 'published', 'verb' => 'move', 'label' => '4']);
});

test('the result leaves the overlay after a while', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 4');
    overlayBusClose();

    $this->travel(BusOverlay::RESULT_SECONDS + 1)->seconds();

    expect(BusOverlay::forOverlay()['result'])->toBeNull();
});

test('a paused game says PAUSED and stops the countdown', function () {
    $mod = overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');

    overlayBus()->pause($mod, 'orkestera');

    busOverlayPage()
        ->assertOk()
        ->assertSeeHtml('data-bus-state="paused"')
        ->assertSee('data-bus-paused', false)
        ->assertDontSee('data-countdown', false);
});

test('the kill switch shows KILLED and nothing else', function () {
    $mod = overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');

    overlayBus()->kill($mod);

    busOverlayPage()
        ->assertOk()
        ->assertSeeHtml('data-bus-state="killed"')
        ->assertSee('Killed')
        ->assertDontSee('data-option=', false);
    expect(BusOverlay::forOverlay())->toMatchArray(['killed' => true, 'window' => null, 'awaiting' => null, 'result' => null]);
});

test('the kill switch shows KILLED even with no game running', function () {
    overlayBus()->kill(overlayBusModerator());

    busOverlayPage()->assertOk()->assertSeeHtml('data-bus-state="killed"');
    expect(BusOverlay::forOverlay())->toBe(['game' => null, 'killed' => true]);
});

test('anarchy explains itself instead of a vote', function () {
    overlayBusStart(Mode::Anarchy);

    busOverlayPage()->assertOk()->assertSee('Anarchy: every');
});

// --- Privacy of the snapshot (the overlay render and the bus.tally payload) --

test('the snapshot carries no user data at all', function () {
    $mod = overlayBusStart();
    $voters = User::factory()->count(3)->create(['name' => fn () => 'Voter '.Str::random(8)]);
    overlayBusSay($voters[0], '!do move 3');
    overlayBusSay($voters[1], '!do #1');
    overlayBusSay($voters[2], '!do task Write the README');

    $snapshot = BusOverlay::snapshot(Game::find('orkestera'));
    $json = json_encode($snapshot);

    foreach ($voters->push($mod) as $user) {
        expect($json)->not->toContain($user->name)
            ->not->toContain((string) $user->twitch_id);
    }
    // Only these keys, at every level.
    expect(array_keys($snapshot))->toBe(['version', 'game', 'running', 'killed', 'paused', 'mode', 'mode_label', 'window', 'awaiting', 'result'])
        ->and(array_keys($snapshot['window']))->toBe(['id', 'seconds_left', 'closes_at', 'total', 'options'])
        ->and(array_keys($snapshot['window']['options'][0]))->toBe(['number', 'verb', 'label', 'votes', 'vetoed']);
});

test('the overlay page carries no user data either', function () {
    overlayBusStart();
    $voter = User::factory()->create(['name' => 'Distinctive Voter Name']);
    overlayBusSay($voter, '!do move 3');

    busOverlayPage()->assertOk()->assertDontSee('Distinctive Voter Name');
});

// --- The coalesced bus.tally ------------------------------------------------

test('with broadcasting off, bus changes queue nothing', function () {
    Queue::fake();
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');

    Queue::assertNotPushed(BroadcastBusTally::class);
});

test('a burst of ballots queues one delayed tally on the broadcasts queue', function () {
    config(['broadcasting.default' => 'reverb']);
    Queue::fake();
    overlayBusStart();

    foreach (range(1, 5) as $n) {
        overlayBusSay(User::factory()->create(), '!do move '.$n);
    }

    Queue::assertPushedOn('broadcasts', BroadcastBusTally::class);
    Queue::assertPushed(BroadcastBusTally::class, 1);
    Queue::assertPushed(BroadcastBusTally::class, fn (BroadcastBusTally $job) => $job->game === 'orkestera' && $job->delay !== null);
});

test('the tally job clears its flag first, so a change during the send queues the next tally', function () {
    config(['broadcasting.default' => 'reverb']);
    Queue::fake();
    Event::fake([BusTallyChanged::class]);
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');
    Queue::assertPushed(BroadcastBusTally::class, 1);

    (new BroadcastBusTally('orkestera'))->handle();
    expect(Cache::has(BroadcastBusTally::pendingKey('orkestera')))->toBeFalse();

    overlayBusSay(User::factory()->create(), '!do #1');
    Queue::assertPushed(BroadcastBusTally::class, 2);
});

test('the flag is already clear while the tally is being sent, so no change in that moment is lost', function () {
    // A real send through a broadcaster that drops everything: the listener
    // sees the event at the moment it goes out.
    Broadcast::extend('discard', fn () => new NullBroadcaster);
    config(['broadcasting.connections.discard' => ['driver' => 'discard'], 'broadcasting.default' => 'discard']);
    Queue::fake();
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');
    expect(Cache::has(BroadcastBusTally::pendingKey('orkestera')))->toBeTrue();

    $flagWhileSending = null;
    Event::listen(BusTallyChanged::class, function () use (&$flagWhileSending) {
        $flagWhileSending = Cache::has(BroadcastBusTally::pendingKey('orkestera'));
    });

    (new BroadcastBusTally('orkestera'))->handle();

    expect($flagWhileSending)->toBeFalse();
});

test('the tally job broadcasts the snapshot as bus.tally on bus.{game}', function () {
    config(['broadcasting.default' => 'reverb']);
    Queue::fake();
    Event::fake([BusTallyChanged::class]);
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');

    (new BroadcastBusTally('orkestera'))->handle();

    Event::assertDispatched(BusTallyChanged::class, function (BusTallyChanged $event) {
        return $event->broadcastOn()[0]->name === 'bus.orkestera'
            && $event->broadcastAs() === 'bus.tally'
            && $event->broadcastWith()['window']['options'][0]['votes'] === 1;
    });
});

test('mass updates reach the tally through the moderation log: option veto and kill', function () {
    config(['broadcasting.default' => 'reverb']);
    Queue::fake();
    $mod = overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');
    Cache::flush();

    overlayBus()->vetoOption($mod, BusWindow::open()->sole(), 'move:3');
    Queue::assertPushed(BroadcastBusTally::class, 2);

    Cache::flush();
    overlayBus()->kill($mod);
    Queue::assertPushed(BroadcastBusTally::class, 3);
});

// --- Token and live hook ----------------------------------------------------

test('the overlay renders the live hook with every game\'s channel and its snapshot', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');

    $html = busOverlayPage()->assertOk()->getContent();

    expect($html)->toContain('x-data="liveBus"')
        ->toContain('data-live="on"')
        ->toContain('data-bus-games="'.e(json_encode(array_keys(Game::all()))).'"')
        ->toContain('data-snapshot="')
        ->not->toContain('wire:poll')
        ->not->toContain('echo:');
});

test('a rotated token blanks the overlay and switches its client off', function () {
    OverlayToken::issue(Overlay::Bus);
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');
    $component = Volt::test('overlays.bus')->assertSeeHtml('data-live="on"')->assertSeeHtml('data-option="1"');

    OverlayToken::issue(Overlay::Bus);

    $component->call('$refresh')
        ->assertSeeHtml('data-live="off"')
        ->assertDontSeeHtml('data-option=')
        ->assertSeeHtml('data-overlay-empty');
});

test('without a token the bus overlay serves only the bootstrap page', function () {
    overlayBusStart();
    overlayBusSay(User::factory()->create(), '!do move 3');

    $this->get(route('overlay.show', ['overlay' => 'bus']))
        ->assertOk()
        ->assertViewIs('overlays.bootstrap')
        ->assertDontSee('data-option', false);
});
