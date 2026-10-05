<?php

use App\Agent\AgentControls;
use App\Agent\AgentGate;
use App\Agent\AgentTokens;
use App\Agent\AvatarDriver;
use App\Chat\ChatCommandRegistry;
use App\ControlBus\BallotStatus;
use App\ControlBus\ControlBus;
use App\ControlBus\Mode;
use App\Events\KillSwitchThrown;
use App\IdentityProvider;
use App\Models\Agent;
use App\Models\AgentClaim;
use App\Models\AgentRequest;
use App\Models\BusBallot;
use App\Models\BusControl;
use App\Models\ModerationAction;
use App\Models\Question;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Readiness\ReadinessChecks;
use App\Readiness\Status as ReadinessStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;
use Symfony\Component\HttpKernel\Exception\HttpException;

// The VTuber agent bridge (#10). Requests go through the real HTTP stack:
// the request log, Sanctum, the agent check, the rate limit and the
// kill-switch gate.

/**
 * @param  list<string>|null  $abilities
 * @return array{0: Agent, 1: string}
 */
function agentWithToken(string $name = 'vtuber', ?array $abilities = null): array
{
    $agent = Agent::named($name);

    return [$agent, $agent->createToken('agent', $abilities ?? Agent::ABILITIES)->plainTextToken];
}

function agentModerator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function agentPage(): Testable
{
    // An earlier API call in the same test made Sanctum the default guard.
    app('auth')->shouldUse('web');

    return Livewire::test(FragmentAlias::encode('agent', resource_path('views/agent.blade.php')));
}

/**
 * A fresh request, as production gets one: tests share one application, so
 * forget the guards an earlier request resolved (Sanctum's caches its user).
 */
function fresh(): mixed
{
    app('auth')->forgetGuards();

    return test();
}

/** Every agent route, with a body each would accept. */
function agentRoutes(Question $question): array
{
    return [
        ['GET', '/api/agent/queue', []],
        ['POST', "/api/agent/questions/{$question->id}/claim", ['reason' => 'Top voted']],
        ['POST', "/api/agent/questions/{$question->id}/answer", ['answer' => 'Hello', 'moderation' => ['verdict' => 'allowed']]],
        ['POST', '/api/agent/expression', ['expression' => 'happy']],
        ['POST', '/api/agent/bus/actions', ['action' => 'task Write the README']],
    ];
}

beforeEach(function () {
    config(['bus.platforms' => ['twitch'], 'agent.avatar.driver' => 'log', 'agent.obs.driver' => 'log']);
});

// --- Authentication and abilities ---------------------------------------------

test('the agent API needs an agent token, and refusals are logged too', function () {
    fresh()->getJson('/api/agent/queue')->assertUnauthorized();
    fresh()->withToken('nonsense')->getJson('/api/agent/queue')->assertUnauthorized();

    expect(AgentRequest::orderBy('id')->get()->map(fn ($r) => [$r->status, $r->refused, $r->agent_id])->all())
        ->toBe([[401, 'unauthenticated', null], [401, 'unauthenticated', null]]);
});

test('a signed-in browser session cannot drive the agent API', function () {
    $this->actingAs(agentModerator(), 'web')->getJson('/api/agent/queue')->assertUnauthorized();
});

test('a moderator\'s kill-switch token is not an agent token', function () {
    $token = agentModerator()->createToken('kill-switch', ['kill-switch'])->plainTextToken;

    fresh()->withToken($token)->getJson('/api/agent/queue')->assertForbidden();
});

test('each route needs its ability', function () {
    [, $token] = agentWithToken(abilities: ['agent:queue']);

    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();
    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertForbidden();
    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => 'task x'])->assertForbidden();
});

// --- Queue, claims and answers ----------------------------------------------

test('the queue lists the next questions, most votes first, leaving out ones another agent holds', function () {
    [$agent, $token] = agentWithToken();
    [$other] = agentWithToken('other-agent');
    $popular = Question::factory()->create(['question' => 'Popular one']);
    $quiet = Question::factory()->create(['question' => 'Quiet one']);
    $taken = Question::factory()->create(['question' => 'Taken by another']);
    $popular->recordVote(User::factory()->create(), 1);
    AgentClaim::create(['agent_id' => $other->id, 'question_id' => $taken->id, 'question_text' => $taken->question, 'reason' => 'mine', 'claimed_at' => now()]);
    AgentClaim::create(['agent_id' => $agent->id, 'question_id' => $quiet->id, 'question_text' => $quiet->question, 'reason' => 'mine', 'claimed_at' => now()]);

    $response = fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();

    expect($response->json('questions'))->toBe([
        ['id' => $popular->id, 'question' => 'Popular one', 'votes' => 1, 'author' => $popular->user->name, 'claimed_by_me' => false],
        ['id' => $quiet->id, 'question' => 'Quiet one', 'votes' => 0, 'author' => $quiet->user->name, 'claimed_by_me' => true],
    ]);
    fresh()->withToken($token)->getJson('/api/agent/queue?limit=1')->assertJsonCount(1, 'questions');
});

test('an agent claims a question and says why', function () {
    [$agent, $token] = agentWithToken();
    $question = Question::factory()->create(['question' => 'Sing about kale']);

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'Most votes and on topic'])
        ->assertCreated()
        ->assertJsonPath('claim.reason', 'Most votes and on topic');
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'Again'])->assertOk();

    expect(AgentClaim::sole()->only(['agent_id', 'question_id', 'question_text', 'reason']))
        ->toBe(['agent_id' => $agent->id, 'question_id' => $question->id, 'question_text' => 'Sing about kale', 'reason' => 'Most votes and on topic']);
});

test('a claim is refused when another agent holds the question, it is archived, or no reason is given', function () {
    [, $first] = agentWithToken('first');
    [, $second] = agentWithToken('second');
    $question = Question::factory()->create();

    fresh()->withToken($first)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine'])->assertCreated();
    fresh()->withToken($second)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine too'])->assertConflict();
    fresh()->withToken($second)->postJson('/api/agent/questions/'.Question::factory()->create(['archived_at' => now()])->id.'/claim', ['reason' => 'old'])->assertUnprocessable();
    fresh()->withToken($second)->postJson('/api/agent/questions/'.Question::factory()->create()->id.'/claim', [])->assertJsonValidationErrors('reason');
    fresh()->withToken($second)->postJson('/api/agent/questions/999999/claim', ['reason' => 'gone'])->assertNotFound();
});

test('an answer is stored with the moderation verdict, once, and only after a claim', function () {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    $answer = ['answer' => 'Kale is a leafy green.', 'moderation' => ['verdict' => 'flagged', 'categories' => ['food'], 'model' => 'omni-moderation', 'notes' => 'mild']];

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", $answer)->assertConflict();
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'on topic'])->assertCreated();
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", $answer)->assertCreated()->assertJsonPath('claim.moderation_verdict', 'flagged');
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", $answer)->assertConflict();

    $claim = AgentClaim::sole();
    expect($claim->answer)->toBe('Kale is a leafy green.')
        ->and($claim->moderation)->toEqual($answer['moderation'])
        ->and($claim->answered_at)->not->toBeNull()
        ->and(fresh()->withToken($token)->getJson('/api/agent/queue')->json('questions'))->toBeEmpty();
});

test('an answer needs a known verdict', function (array $moderation) {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'on topic']);

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", ['answer' => 'Hi', 'moderation' => $moderation])
        ->assertJsonValidationErrorFor('moderation.verdict');
})->with([[['verdict' => 'fine']], [[]]]);

// --- Avatar expressions -------------------------------------------------------

test('an expression goes to VTube Studio as a hotkey trigger', function () {
    Http::fake(['vts.test/*' => Http::response(['ok' => true])]);
    config(['agent.avatar' => ['driver' => 'vtube_studio', 'url' => 'http://vts.test/api', 'token' => 'bridge-secret', 'timeout_seconds' => 3, 'expressions' => ['happy' => 'hk-123']]]);
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertOk()->assertJson(['sent' => true]);

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://vts.test/api'
        && $r['messageType'] === 'HotkeyTriggerRequest'
        && $r['data'] === ['hotkeyID' => 'hk-123']
        && $r->hasHeader('Authorization', 'Bearer bridge-secret'));
});

test('an expression goes to Warudo by name', function () {
    Http::fake(['warudo.test/*' => Http::response()]);
    config(['agent.avatar' => ['driver' => 'warudo', 'url' => 'http://warudo.test/expression', 'expressions' => ['surprised' => 'Shock']]]);
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'surprised'])->assertOk();

    Http::assertSent(fn (HttpRequest $r) => $r->data() === ['action' => 'expression', 'name' => 'Shock']);
});

test('only configured expressions are accepted, and an avatar app failure is a 502', function () {
    Http::fake(['vts.test/*' => Http::response('down', 500)]);
    config(['agent.avatar' => ['driver' => 'vtube_studio', 'url' => 'http://vts.test/api', 'expressions' => ['happy' => 'hk-1']]]);
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'evil grin'])->assertJsonValidationErrors('expression');
    $this->travel(2)->seconds();   // expressions are limited to one a second
    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertStatus(502);
});

// --- Acting through the Chat Control Bus --------------------------------------

test('an agent acts through the bus as one participant, and free text still waits for a moderator', function () {
    $mod = agentModerator();
    app(ControlBus::class)->setActiveGame($mod, 'orkestera');
    [$agent, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => 'task Write the README'])
        ->assertStatus(202)->assertJson(['accepted' => true, 'status' => 'counted']);
    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => 'task Fix the tests'])->assertStatus(202);

    $ballots = BusBallot::orderBy('id')->get();
    expect($ballots->pluck('status')->all())->toBe([BallotStatus::Replaced, BallotStatus::Counted])
        ->and($ballots->pluck('agent_id')->unique()->all())->toBe([$agent->id])
        ->and($ballots->pluck('user_id')->unique()->all())->toBe([$agent->user_id])
        ->and($ballots->first()->provider)->toBeNull();

    // In anarchy a task waits for approval rather than publishing.
    app(ControlBus::class)->setMode($mod, 'orkestera', Mode::Anarchy);
    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => 'task Deploy it'])->assertJson(['status' => 'pending_approval']);
});

test('a refused bus action says why, with no text from the action', function () {
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => 'task Write the README'])
        ->assertUnprocessable()
        ->assertExactJson(['accepted' => false, 'status' => 'no_game', 'reason' => 'no_game', 'ballot_id' => BusBallot::sole()->id]);
});

// --- The kill switch: a hard gate on every request ----------------------------

test('the request right after the kill switch is thrown is refused, on every route', function () {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();

    app(ControlBus::class)->kill(agentModerator(), 'agent went rogue');

    foreach (agentRoutes($question) as [$method, $uri, $body]) {
        fresh()->withToken($token)->json($method, $uri, $body)
            ->assertStatus(423)
            ->assertJson(['refused' => 'killed'])
            ->assertHeader('X-Agent-Refused', 'killed');
    }

    expect(AgentClaim::count())->toBe(0)
        ->and(BusBallot::count())->toBe(0)
        ->and(AgentRequest::where('refused', 'killed')->count())->toBe(5);
});

test('a kill thrown by another process is honoured on the next request: the switch is never cached', function () {
    [, $token] = agentWithToken();
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();

    BusControl::for(BusControl::GLOBAL)->update(['killed_at' => now()]);
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertStatus(423);

    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => null]);
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();
});

test('the kill is refused within the second: the very next request, with no time passing', function () {
    $this->freezeTime();
    [, $token] = agentWithToken();
    $mod = agentModerator();

    $before = fresh()->withToken($token)->getJson('/api/agent/queue');
    app(ControlBus::class)->kill($mod);
    $after = fresh()->withToken($token)->getJson('/api/agent/queue');

    expect($before->status())->toBe(200)->and($after->status())->toBe(423)
        ->and(AgentRequest::orderBy('id')->pluck('created_at')->map->getTimestamp()->unique()->count())->toBe(1);
});

test('an action already past the gate is refused again right before its effect', function () {
    Http::fake();
    config(['agent.avatar' => ['driver' => 'vtube_studio', 'url' => 'http://vts.test/api', 'expressions' => ['happy' => 'hk-1']]]);
    [, $token] = agentWithToken();

    // The kill lands after the middleware ran, while the controller is being
    // called: as a moderator's kill would, mid-request.
    app()->bind(AvatarDriver::class, function () {
        BusControl::for(BusControl::GLOBAL)->update(['killed_at' => now()]);

        return new AvatarDriver;
    });

    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertStatus(423);
    Http::assertNothingSent();
});

test('the gate refuses with its reason', function () {
    expect(AgentGate::refusal())->toBeNull();

    config(['agent.enabled' => false]);
    expect(AgentGate::refusal())->toBe('disabled');

    config(['agent.enabled' => true, 'bus.enabled' => false]);
    expect(AgentGate::refusal())->toBe('killed');

    config(['bus.enabled' => true]);
    BusControl::for(AgentGate::SCOPE)->update(['paused_at' => now()]);
    expect(AgentGate::refusal())->toBe('paused')
        ->and(fn () => AgentGate::ensure())->toThrow(HttpException::class);
});

test('stopping only the agent leaves the chat game running', function () {
    $mod = agentModerator();
    app(ControlBus::class)->setActiveGame($mod, 'orkestera');
    [, $token] = agentWithToken();
    $this->actingAs($mod, 'web');

    agentPage()->call('stopAgent');
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertStatus(423)->assertJson(['refused' => 'paused']);

    $viewer = User::factory()->create();
    app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', (string) $viewer->twitch_id, $viewer->name, (string) Str::uuid(), '!do task Write the README');
    expect(BusBallot::sole()->status)->toBe(BallotStatus::Counted);

    $this->actingAs($mod, 'web');
    agentPage()->call('startAgent');
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();
    expect(ModerationAction::orderBy('id')->pluck('action')->all())->toContain('agent.stopped', 'agent.started');
});

// --- Intermission -------------------------------------------------------------

test('the kill switch cuts to the intermission scene through obs-websocket', function () {
    Http::fake(['obs.test/*' => Http::response(['result' => true])]);
    config(['agent.obs' => ['driver' => 'obs_http', 'url' => 'http://obs.test/', 'token' => 'obs-secret', 'intermission_scene' => 'Be right back', 'timeout_seconds' => 2]]);
    $mod = agentModerator();

    app(ControlBus::class)->kill($mod, 'testing');

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://obs.test/emit/SetCurrentProgramScene'
        && $r->data() === ['sceneName' => 'Be right back']
        && $r->hasHeader('Authorization', 'obs-secret'));
    expect(ModerationAction::where('action', 'bus.intermission')->sole()->only(['moderator_id', 'details']))
        ->toBe(['moderator_id' => $mod->id, 'details' => ['scene' => 'Be right back', 'driver' => 'obs_http', 'switched' => true]]);
});

test('if OBS fails the kill switch still holds, and the failure is recorded', function () {
    Http::fake(['obs.test/*' => Http::response('nope', 500)]);
    config(['agent.obs' => ['driver' => 'obs_http', 'url' => 'http://obs.test', 'intermission_scene' => 'Intermission']]);
    [, $token] = agentWithToken();

    app(ControlBus::class)->kill(agentModerator());

    expect(ModerationAction::where('action', 'bus.intermission')->sole()->details['switched'])->toBeFalse();
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertStatus(423);
});

test('every way of throwing the kill switch cuts to intermission', function () {
    Event::fake([KillSwitchThrown::class]);
    $mod = agentModerator();

    app(ControlBus::class)->kill($mod);
    app(ControlBus::class)->restore(User::factory()->twitch('1000')->create());
    $this->artisan('bus:kill')->assertSuccessful();
    $this->artisan('bus:kill', ['--off' => true])->assertSuccessful();
    $this->actingAs($mod, 'web');
    agentPage()->call('kill');

    Event::assertDispatchedTimes(KillSwitchThrown::class, 3);
});

// --- The kill-switch endpoint -------------------------------------------------

test('a moderator\'s token throws the kill switch from a Stream Deck, and nothing else can', function () {
    $mod = agentModerator();
    $token = $mod->createToken('kill-switch', ['kill-switch'])->plainTextToken;
    [, $agentToken] = agentWithToken();
    $viewerToken = User::factory()->create()->createToken('kill-switch', ['kill-switch'])->plainTextToken;
    $noAbility = $mod->createToken('other', ['something'])->plainTextToken;

    fresh()->postJson('/api/kill-switch')->assertUnauthorized();
    fresh()->withToken($viewerToken)->postJson('/api/kill-switch')->assertForbidden();
    fresh()->withToken($noAbility)->postJson('/api/kill-switch')->assertForbidden();
    fresh()->withToken($agentToken)->postJson('/api/kill-switch')->assertForbidden();
    expect(BusControl::find(BusControl::GLOBAL)?->killed_at)->toBeNull();

    fresh()->withToken($token)->postJson('/api/kill-switch', ['reason' => 'stream deck'])->assertOk()->assertJson(['killed' => true]);

    expect(BusControl::find(BusControl::GLOBAL)->killed_at)->not->toBeNull()
        ->and(ModerationAction::where('action', 'bus.killed')->sole()->details['reason'])->toBe('stream deck');
    fresh()->withToken($agentToken)->getJson('/api/agent/queue')->assertStatus(423);
});

// --- The request log ----------------------------------------------------------

test('every agent request and response is logged in full, without the token', function () {
    [$agent, $token] = agentWithToken();
    $question = Question::factory()->create(['question' => 'Sing about kale']);

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'On topic and popular'])->assertCreated();

    $logged = AgentRequest::sole();
    expect($logged->only(['agent_id', 'method', 'path', 'route', 'status', 'refused']))
        ->toBe(['agent_id' => $agent->id, 'method' => 'POST', 'path' => "/api/agent/questions/{$question->id}/claim", 'route' => 'agent.claim', 'status' => 201, 'refused' => null])
        ->and(json_decode($logged->request, true))->toBe(['reason' => 'On topic and popular'])
        ->and(json_decode($logged->response, true)['claim']['question'])->toBe('Sing about kale')
        ->and($logged->token_id)->toBe($agent->tokens()->sole()->id)
        ->and(AgentRequest::query()->where('request', 'like', '%'.explode('|', $token)[1].'%')->orWhere('response', 'like', '%'.explode('|', $token)[1].'%')->exists())->toBeFalse();
});

test('a huge body is logged truncated', function () {
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => str_repeat('x', 70_000)])->assertUnprocessable();

    expect(strlen(AgentRequest::sole()->request))->toBe(16 * 1024)
        ->and(AgentRequest::sole()->request)->toEndWith(AgentRequest::TRUNCATED);
});

// --- The /agent page ----------------------------------------------------------

test('/agent is for moderators, and shows what the agent took, why, and what it said', function () {
    [$agent, $token] = agentWithToken();
    $question = Question::factory()->create(['question' => 'Sing about kale']);
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'Most votes']);
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", ['answer' => 'Kale is green.', 'moderation' => ['verdict' => 'allowed']]);

    $this->actingAs(User::factory()->create(), 'web')->get('/agent')->assertForbidden();
    $this->actingAs(agentModerator(), 'web')->get('/agent')->assertOk()
        ->assertSee('Sing about kale')->assertSee('Most votes')->assertSee('Kale is green.')->assertSee('allowed')
        ->assertSee('/api/agent/questions/'.$question->id.'/claim');
});

test('the kill button on /agent stops everything, and a viewer cannot press it', function () {
    [, $token] = agentWithToken();
    $this->actingAs(User::factory()->create(), 'web');
    agentPage()->call('kill')->assertForbidden();
    agentPage()->call('stopAgent')->assertForbidden();
    expect(AgentGate::refusal())->toBeNull();

    $this->actingAs(agentModerator(), 'web');
    agentPage()->set('reason', 'bad answer')->call('kill')->assertSee('Kill switch is on');

    fresh()->withToken($token)->getJson('/api/agent/queue')->assertStatus(423);
    expect(ModerationAction::where('action', 'bus.killed')->sole()->details['reason'])->toBe('bad answer');
});

test('a moderator opens a logged request to read its bodies', function () {
    [, $token] = agentWithToken();
    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy']);
    $this->actingAs(agentModerator(), 'web');

    agentPage()->assertDontSee('"expression":"happy"')->call('showRequest', AgentRequest::sole()->id)->assertSee('"expression":"happy"');
});

// --- Tokens -------------------------------------------------------------------

test('agent:token issues a token once, creating the agent and its service user', function () {
    $this->artisan('agent:token', ['name' => 'orkestera-vtuber'])->expectsOutputToContain('Authorization: Bearer ')->assertSuccessful();
    $this->artisan('agent:token', ['name' => 'orkestera-vtuber'])->assertFailed();
    $this->artisan('agent:token', ['name' => 'orkestera-vtuber', '--rotate' => true, '--ability' => ['agent:queue']])->assertSuccessful();
    $this->artisan('agent:token', ['name' => 'Bad Name!'])->assertExitCode(2);
    $this->artisan('agent:token', ['name' => 'x', '--ability' => ['root']])->assertExitCode(2);

    $agent = Agent::where('name', 'orkestera-vtuber')->sole();
    expect($agent->tokens()->sole()->abilities)->toBe(['agent:queue'])
        ->and($agent->user->name)->toBe('orkestera-vtuber (agent)');
});

test('agent:kill-token is for moderators only', function () {
    $mod = agentModerator();

    $this->artisan('agent:kill-token', ['user' => $mod->id])->expectsOutputToContain('Authorization: Bearer ')->assertSuccessful();
    $this->artisan('agent:kill-token', ['user' => $mod->id])->assertFailed();
    $this->artisan('agent:kill-token', ['user' => User::factory()->create()->id])->assertExitCode(2);

    expect($mod->tokens()->sole()->abilities)->toBe(['kill-switch']);
});

// --- Review round 1 (#153) ----------------------------------------------------

test('Andras A153-1: junk from an address is refused with 429 before it can grow the log', function () {
    config(['agent.failed_auth_per_minute' => 30]);
    $body = ['answer' => str_repeat('x', 20000), 'moderation' => ['verdict' => 'allowed']];

    $statuses = [];
    foreach (range(1, 300) as $i) {
        $statuses[] = fresh()->withToken('not-a-token')->postJson('/api/agent/questions/1/answer', $body)->status();
    }

    expect(array_count_values($statuses))->toBe([401 => 30, 429 => 270])
        ->and(AgentRequest::count())->toBe(30)
        ->and(AgentRequest::whereNotNull('request')->orWhereNotNull('response')->count())->toBe(0);
});

test('failed auth is limited per address, and a working agent never spends that budget', function () {
    config(['agent.failed_auth_per_minute' => 3, 'agent.requests_per_minute' => 1000]);
    [, $token] = agentWithToken();

    foreach (range(1, 10) as $i) {
        fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();
    }
    foreach (range(1, 3) as $i) {
        fresh()->withToken('bad')->getJson('/api/agent/queue')->assertUnauthorized();
    }
    fresh()->withToken('bad')->getJson('/api/agent/queue')->assertTooManyRequests();

    $this->travel(61)->seconds();
    fresh()->withToken('bad')->getJson('/api/agent/queue')->assertUnauthorized();
});

test('a refused-auth request is logged without bodies; an authenticated one keeps them', function () {
    [, $token] = agentWithToken();

    fresh()->withToken('bad')->postJson('/api/agent/expression', ['expression' => 'happy'])->assertUnauthorized();
    fresh()->withToken(agentModerator()->createToken('kill-switch', ['kill-switch'])->plainTextToken)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertForbidden();
    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertOk();

    expect(AgentRequest::orderBy('id')->get()->map(fn ($r) => [$r->status, $r->request !== null, $r->response !== null])->all())
        ->toBe([[401, false, false], [403, false, false], [200, true, true]]);
});

test('the request log is pruned after AGENT_LOG_DAYS', function () {
    config(['agent.log_days' => 14]);
    [, $token] = agentWithToken();
    fresh()->withToken($token)->getJson('/api/agent/queue');
    $this->travel(15)->days();
    fresh()->withToken($token)->getJson('/api/agent/queue');

    $this->artisan('model:prune', ['--model' => [AgentRequest::class]])->assertSuccessful();

    expect(AgentRequest::count())->toBe(1)
        ->and(AgentRequest::sole()->created_at->isToday())->toBeTrue();
});

test('the scheduler prunes the agent request log daily', function () {
    $prune = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'model:prune'));

    expect($prune->command)->toContain("--model='".AgentRequest::class."'")
        ->and($prune->expression)->toBe('0 0 * * *');
});

test('expressions are limited to one a second on their own', function () {
    config(['agent.requests_per_minute' => 1000]);
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertOk();
    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'sad'])->assertTooManyRequests();
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();

    $this->travel(2)->seconds();
    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'sad'])->assertOk();
});

test('a kill landing during a claim rolls the claim back', function () {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    AgentClaim::creating(fn () => BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]));

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine'])->assertStatus(423);

    expect(AgentClaim::count())->toBe(0);
});

test('a kill landing during an answer rolls the answer back', function () {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine'])->assertCreated();
    AgentClaim::updating(fn () => BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]));

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", ['answer' => 'Hi', 'moderation' => ['verdict' => 'allowed']])->assertStatus(423);

    expect(AgentClaim::sole()->answered_at)->toBeNull();
});

test('every agent effect holds the switch rows FOR SHARE, and the kill and the agent stop take them FOR UPDATE', function () {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    // The lock query itself (AgentGate::refusal() reads the same rows, but
    // selects only its columns and takes no lock).
    $switchRows = fn (array $q) => str_starts_with($q['query'], 'select * from "bus_controls" where "scope" in') && str_contains($q['query'], 'order by "scope" asc');

    foreach ([
        fn () => fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine']),
        fn () => fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", ['answer' => 'Hi', 'moderation' => ['verdict' => 'allowed']]),
        fn () => fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy']),
    ] as $call) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call()->assertSuccessful();
        $log = collect(DB::getQueryLog());

        $locked = $log->filter($switchRows)->filter(fn ($q) => str_ends_with($q['query'], 'for share'));
        expect($locked)->not->toBeEmpty();
    }

    DB::flushQueryLog();
    app(AgentControls::class)->stop(agentModerator());
    expect(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "bus_controls" where "bus_controls"."scope" = ?') && str_ends_with($q['query'], 'for update')))->toBeTrue();
});

test('agent tokens expire, after AGENT_TOKEN_DAYS or --days', function () {
    config(['agent.token_days' => 30]);

    $this->artisan('agent:token', ['name' => 'vtuber'])->expectsOutputToContain('valid until')->assertSuccessful();
    expect(Agent::sole()->tokens()->sole()->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString());

    $this->artisan('agent:token', ['name' => 'vtuber', '--rotate' => true, '--days' => 7])->assertSuccessful();
    expect(Agent::sole()->tokens()->sole()->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());

    $this->artisan('agent:token', ['name' => 'vtuber', '--rotate' => true, '--days' => 0])->assertExitCode(2);
    $this->artisan('agent:token', ['name' => 'vtuber', '--rotate' => true, '--days' => 400])->assertExitCode(2);
});

test('an expired agent token is refused', function () {
    $agent = Agent::named('vtuber');
    $token = $agent->createToken('agent', Agent::ABILITIES, now()->addDay())->plainTextToken;

    fresh()->withToken($token)->getJson('/api/agent/queue')->assertOk();
    $this->travel(2)->days();
    fresh()->withToken($token)->getJson('/api/agent/queue')->assertUnauthorized();
});

test('/agent and the readiness check show when agent tokens expire', function () {
    $this->freezeTime();
    $fresh = Agent::named('fresh-agent');
    $fresh->createToken('agent', Agent::ABILITIES, now()->addDays(20));
    $soon = Agent::named('soon-agent');
    $soon->createToken('agent', Agent::ABILITIES, now()->addDay());
    $never = Agent::named('legacy-agent');
    $never->createToken('agent', Agent::ABILITIES);

    $check = AgentTokens::readinessCheck();
    expect($check->status)->toBe(ReadinessStatus::Warn)
        ->and($check->summary)->toContain('soon-agent', 'legacy-agent')->not->toContain('fresh-agent')
        ->and($check->details)->toContain('fresh-agent: valid until '.now()->addDays(20)->toDateTimeString());

    $this->actingAs(agentModerator(), 'web')->get('/agent')->assertOk()
        ->assertSee('valid until '.now()->addDays(20)->toDateTimeString())
        ->assertSee('a token never expires');

    $never->tokens()->delete();
    $soon->tokens()->delete();
    expect(AgentTokens::readinessCheck()->status)->toBe(ReadinessStatus::Warn);   // soon-agent has no valid token
    $soon->delete();
    $never->delete();
    expect(AgentTokens::readinessCheck()->status)->toBe(ReadinessStatus::Ok);
});

test('the readiness page has a VTuber agent group, skipped until an agent exists', function () {
    $groups = app(ReadinessChecks::class)->all();

    expect($groups)->toHaveKey('VTuber agent')
        ->and($groups['VTuber agent'][0]->status)->toBe(ReadinessStatus::Skip);
});

test('rolling back the migration deletes agent ballots rather than leaving an invalid provider', function () {
    $mod = agentModerator();
    app(ControlBus::class)->setActiveGame($mod, 'orkestera');
    [, $token] = agentWithToken();
    fresh()->withToken($token)->postJson('/api/agent/bus/actions', ['action' => 'task Write the README'])->assertStatus(202);
    $viewer = User::factory()->create();
    app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', (string) $viewer->twitch_id, $viewer->name, (string) Str::uuid(), '!do task Fix the tests');

    $migration = require database_path('migrations/2026_10_05_010611_create_agent_bridge_tables.php');
    $migration->down();

    expect(DB::table('bus_ballots')->pluck('provider')->all())->toBe(['twitch']);

    $migration->up();
});

// Andras's direct tests on #153: each layer of the kill-switch endpoint's
// authorisation, on its own.
test('Andras A153b-1: a kill-switch token whose owner is no longer a moderator cannot kill or cut to intermission', function () {
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);
    $token = $mod->createToken('kill-switch', ['kill-switch'])->plainTextToken;   // as agent:kill-token issues it
    TwitchModerator::query()->delete();                                            // demoted later

    Event::fake([KillSwitchThrown::class]);
    fresh()->withToken($token)->postJson('/api/kill-switch')->assertForbidden()
        ->assertJson(['message' => "Only a moderator's token can throw the kill switch."]);   // pins the controller's own check

    Event::assertNotDispatched(KillSwitchThrown::class);
    expect(BusControl::find(BusControl::GLOBAL)?->killed_at)->toBeNull()
        ->and(ModerationAction::whereIn('action', ['bus.killed', 'bus.intermission'])->count())->toBe(0);
});

test('Andras A153b-2: the bus refuses a non-moderator before any side effect, independent of the controller', function () {
    Event::fake([KillSwitchThrown::class]);
    expect(fn () => app(ControlBus::class)->kill(User::factory()->create(), 'x'))->toThrow(AuthorizationException::class);
    Event::assertNotDispatched(KillSwitchThrown::class);
    expect(ModerationAction::count())->toBe(0);
});

test('a CLI kill records its intermission cut as the CLI, never as a deleted user', function () {
    $this->artisan('bus:kill')->assertSuccessful();

    $cut = ModerationAction::where('action', 'bus.intermission')->sole();
    expect($cut->moderator_id)->toBeNull()
        ->and($cut->details)->toMatchArray(['via' => 'cli', 'command' => 'bus:kill'])
        ->and($cut->actorName())->not->toBe('deleted user');
});

// #163: a runaway agent at the per-token limit for the whole retention
// window must not be able to write gigabytes of log.
test('stored bodies are capped at AGENT_LOG_BODY_KB, request and response alike', function () {
    config(['agent.log_body_kb' => 2]);
    [, $token] = agentWithToken();
    $question = Question::factory()->create(['question' => str_repeat('q', 400)]);
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => str_repeat('r', 450)])->assertCreated();

    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/answer", [
        'answer' => str_repeat('a', 4000),
        'moderation' => ['verdict' => 'allowed', 'notes' => str_repeat('n', 900)],
    ])->assertCreated();

    $logged = AgentRequest::where('route', 'agent.answer')->sole();
    expect(strlen($logged->request))->toBe(2048)
        ->and($logged->request)->toEndWith(AgentRequest::TRUNCATED)
        ->and(strlen($logged->response))->toBe(2048)
        ->and($logged->response)->toEndWith(AgentRequest::TRUNCATED)
        ->and(AgentClaim::sole()->answer)->toBe(str_repeat('a', 4000));   // the stored answer is not cut
});

test('a body under the cap is kept whole, with no marker', function () {
    [, $token] = agentWithToken();

    fresh()->withToken($token)->postJson('/api/agent/expression', ['expression' => 'happy'])->assertOk();

    expect(AgentRequest::sole()->request)->toBe('{"expression":"happy"}');
});

test('the cap never splits a multi-byte character', function () {
    config(['agent.log_body_kb' => 1]);

    $clipped = AgentRequest::clip(str_repeat('é', 2000));

    expect(mb_check_encoding($clipped, 'UTF-8'))->toBeTrue()
        ->and(strlen($clipped))->toBeLessThanOrEqual(1024)
        ->and($clipped)->toEndWith(AgentRequest::TRUNCATED);
});

test('the body cap is held between 1 KB and 63 KB', function (int $configured, int $bytes) {
    config(['agent.log_body_kb' => $configured]);

    expect(AgentRequest::maxBodyBytes())->toBe($bytes);
})->with([[16, 16384], [0, 1024], [-5, 1024], [63, 64512], [500, 64512]]);

// --- Switch rows without failing statements (#181) -----------------------------

/** How many transactions or savepoints rolled back while $callback ran (see ControlBusTest). */
function agentRollbacks(callable $callback): int
{
    $rollbacks = 0;
    Event::listen(TransactionRolledBack::class, function () use (&$rollbacks) {
        $rollbacks++;
    });
    $callback();

    return $rollbacks;
}

test('an agent effect runs no failing statement and creates no switch row (runs on Postgres in CI)', function () {
    [, $token] = agentWithToken();
    [$first, $question] = Question::factory()->count(2)->create();

    // The first effect creates the switch rows, without a failing statement.
    expect(agentRollbacks(fn () => fresh()->withToken($token)->postJson("/api/agent/questions/{$first->id}/claim", ['reason' => 'mine'])->assertSuccessful()))->toBe(0);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rollbacks = agentRollbacks(fn () => fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine'])->assertSuccessful());
    $inserts = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with($sql, 'insert') && str_contains($sql, 'bus_controls'));
    DB::disableQueryLog();

    expect($rollbacks)->toBe(0)->and($inserts)->toBeEmpty();
});

test('with its switch rows missing, an agent effect creates them, holds them and still honours the kill switch', function () {
    [, $token] = agentWithToken();
    $question = Question::factory()->create();
    BusControl::query()->delete();

    expect(agentRollbacks(fn () => AgentGate::whileAllowed(fn () => null)))->toBe(0)
        ->and(BusControl::whereIn('scope', [BusControl::GLOBAL, AgentGate::SCOPE])->count())->toBe(2);

    BusControl::whereKey(BusControl::GLOBAL)->update(['killed_at' => now()]);
    fresh()->withToken($token)->postJson("/api/agent/questions/{$question->id}/claim", ['reason' => 'mine'])->assertStatus(423);
});
