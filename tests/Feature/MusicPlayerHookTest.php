<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Enums\SongRequestStatus;
use App\IdentityProvider;
use App\Models\ModerationAction;
use App\Models\MusicPlayerToken;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\TwitchModerator;
use App\Models\User;
use App\SongRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000']);
});

function hookModerator(): User
{
    $mod = User::factory()->twitch()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function npCommand(string $text, ?User $as = null, string $channel = '1000'): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, $channel, $as?->twitch_id ?? '8880001', 'chatter', (string) Str::uuid(), $text);
}

/** Two queued requests, oldest first. */
function twoQueued(): array
{
    return [
        SongRequest::factory()->for(Track::factory()->streamSafe()->create(['title' => 'First Song', 'artist' => 'EDOS']))->create(['requester_name' => 'fan1']),
        SongRequest::factory()->for(Track::factory()->streamSafe()->create(['title' => 'Second Song', 'artist' => 'EDOS']))->create(['requester_name' => 'fan2']),
    ];
}

function advanceHook(?string $token, array $body = [])
{
    return test()->withHeaders($token === null ? [] : ['Authorization' => 'Bearer '.$token])
        ->postJson(route('music.requests.advance'), $body);
}

// --- SongRequests::advance ---------------------------------------------------

test('advance finishes what is on air, starts the oldest queued request and is audited', function () {
    $mod = hookModerator();
    [$first, $second] = twoQueued();

    expect(SongRequests::advance($mod)->is($first))->toBeTrue();
    expect(SongRequests::advance($mod)->is($second))->toBeTrue()
        ->and($first->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and($second->refresh()->status)->toBe(SongRequestStatus::Playing);

    // An empty queue: the last one is played and nothing is on air.
    expect(SongRequests::advance($mod))->toBeNull()
        ->and($second->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and(SongRequests::nowPlaying())->toBeNull();

    $audit = ModerationAction::where('action', 'song_request.advanced')->orderBy('id')->get();
    expect($audit)->toHaveCount(3)
        ->and($audit[0]->moderator_id)->toBe($mod->id)
        ->and($audit[1]->details)->toBe(['finished_id' => $first->id, 'playing_id' => $second->id, 'track_id' => $second->track_id])
        ->and($audit[2]->details)->toBe(['finished_id' => $second->id]);
});

// Two advances at once (a double-tapped Shortcut, or the hook and a moderator)
// must each move one step. A real race needs two connections, so pin that the
// rows are read under a lock before anything is written.
test('advance locks the playing and next requests before changing them', function () {
    twoQueued();

    DB::enableQueryLog();
    SongRequests::advance(hookModerator());
    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();

    $reads = $queries->keys()->filter(fn (int $i) => preg_match('/^select .* from ["`]?song_requests["`]?/i', $queries[$i]) === 1)->values();
    $firstWrite = $queries->search(fn (string $sql) => preg_match('/^update ["`]?song_requests["`]?/i', $sql) === 1);

    expect($reads)->toHaveCount(2)
        ->and($firstWrite)->toBeInt()
        ->and($reads->max())->toBeLessThan($firstWrite);

    $reads->each(fn (int $i) => expect(strtolower($queries[$i]))->toContain('for update'));
});

test('advance is moderator-only', function () {
    twoQueued();

    expect(fn () => SongRequests::advance(User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and(SongRequests::nowPlaying())->toBeNull();
});

test('the requests page marks the next request playing from the now-playing card', function () {
    $mod = hookModerator();
    [$first, $second] = twoQueued();
    SongRequests::advance($mod);

    Volt::actingAs($mod)->test('music.requests')
        ->assertSee('Done, play next')
        ->call('playNext');

    expect($first->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and($second->refresh()->status)->toBe(SongRequestStatus::Playing)
        ->and(ModerationAction::where('action', 'song_request.advanced')->count())->toBe(2);
});

// --- !np ---------------------------------------------------------------------

test('!np tells anyone, linked or not, what is playing', function () {
    expect(npCommand('!np')->reply)->toBe('Nothing is playing.');

    [$first] = twoQueued();
    SongRequests::advance(hookModerator());

    $result = npCommand('!np');
    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toBe("Now playing song #{$first->track_id}. Songs: ".route('music.index'))
        // Only ARE ids in replies (#128): no title, no requester name.
        ->and($result->reply)->not->toContain('First Song')
        ->and($result->reply)->not->toContain('fan1');
});

test('moderators move the queue with !np next and !np done', function () {
    $mod = hookModerator();
    [$first, $second] = twoQueued();

    expect(npCommand('!np next', $mod)->reply)->toBe("Now playing song #{$first->track_id}. Songs: ".route('music.index'))
        ->and(npCommand('!np next', $mod)->reply)->toBe("Now playing song #{$second->track_id}. Songs: ".route('music.index'))
        ->and(npCommand('!np done', $mod)->reply)->toBe('Marked the current song as played.')
        ->and($second->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and(npCommand('!np next', $mod)->reply)->toBe('Nothing is playing.');
});

test('viewers, and moderators of another channel, cannot move the queue from chat', function () {
    config(['services.twitch.broadcaster_ids' => ['2000']]);
    twoQueued();
    $viewer = User::factory()->twitch()->create();
    $otherMod = User::factory()->twitch()->create();
    TwitchModerator::create(['broadcaster_id' => '2000', 'twitch_user_id' => $otherMod->twitch_id]);

    foreach ([npCommand('!np next'), npCommand('!np next', $viewer), npCommand('!np done', $otherMod)] as $result) {
        expect($result->status)->toBe(ChatCommandStatus::Rejected)
            ->and($result->reply)->toStartWith('Only moderators of this channel');
    }

    expect(npCommand('!np skip-it', $viewer)->reply)->toBe('Usage: !np, or for moderators !np next | !np done')
        ->and(SongRequests::nowPlaying())->toBeNull();
});

// --- POST /music/requests/advance --------------------------------------------

test('a player token advances the queue and answers with what is now playing', function () {
    $token = MusicPlayerToken::issue('obs');
    [$first, $second] = twoQueued();

    advanceHook($token)
        ->assertOk()
        ->assertJson([
            'action' => 'next',
            'now_playing' => [
                'id' => $first->id,
                'title' => 'First Song',
                'artist' => 'EDOS',
                'requested_by' => 'fan1',
                'attribution' => '"First Song" by EDOS · '.route('music.index'),
            ],
            'queued' => 1,
        ]);

    advanceHook($token, ['action' => 'done'])->assertOk()->assertJson(['action' => 'done', 'now_playing' => null, 'queued' => 1]);

    expect($first->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and($second->refresh()->status)->toBe(SongRequestStatus::Queued)
        ->and(MusicPlayerToken::firstWhere('name', 'obs')->last_used_at)->not->toBeNull();

    $audit = ModerationAction::orderBy('id')->get();
    expect($audit->pluck('action')->all())->toBe(['song_request.advanced', 'song_request.finished'])
        ->and($audit->pluck('moderator_id')->filter()->all())->toBe([])
        ->and($audit[0]->details['player'])->toBe('obs')
        ->and($audit[0]->actorName())->toBe('player obs');
});

test('the hook works with a form body and starts no session', function () {
    $token = MusicPlayerToken::issue('shortcut');
    twoQueued();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->post(route('music.requests.advance'), ['action' => 'next'])
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});

test('the hook refuses a missing, wrong, rotated or revoked token, and logs no token', function () {
    twoQueued();
    $old = MusicPlayerToken::issue('obs');
    $new = MusicPlayerToken::issue('obs');
    $revoked = MusicPlayerToken::issue('shortcut');
    MusicPlayerToken::where('name', 'shortcut')->delete();
    Log::spy();

    foreach ([null, '', str_repeat('a', 64), $old, $revoked] as $token) {
        advanceHook($token)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
    }

    expect(SongRequests::nowPlaying())->toBeNull()
        ->and(ModerationAction::count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), $old))->times(5);

    advanceHook($new)->assertOk();
});

// Feature tests skip CSRF checks, so check the route's middleware directly.
test('the hook route runs without session or CSRF middleware, and with the music-player limiter', function () {
    $router = app('router');
    $middleware = $router->gatherRouteMiddleware($router->getRoutes()->getByName('music.requests.advance'));

    expect($middleware)->not->toContain(ValidateCsrfToken::class)
        ->and($middleware)->not->toContain(StartSession::class)
        ->and($middleware)->toContain('Illuminate\Routing\Middleware\ThrottleRequests:music-player');
});

test('a token in the query string is not accepted', function () {
    $token = MusicPlayerToken::issue('obs');

    $this->postJson(route('music.requests.advance', ['token' => $token]))->assertUnauthorized();
});

test('the hook rejects an unknown action', function () {
    advanceHook(MusicPlayerToken::issue('obs'), ['action' => 'skip'])->assertStatus(422);
});

test('the hook is rate-limited per address, and wrong tokens count', function () {
    $token = MusicPlayerToken::issue('obs');

    for ($i = 0; $i < 20; $i++) {
        advanceHook('wrong')->assertUnauthorized();
    }

    advanceHook($token)->assertStatus(429);
});

// --- music:player-token ------------------------------------------------------

test('music:player-token prints a token once, rotates and revokes it', function () {
    expect(Artisan::call('music:player-token', ['name' => 'obs']))->toBe(0);
    $output = Artisan::output();
    preg_match('/^\s+([a-f0-9]{64})$/m', $output, $m);
    $first = $m[1];
    expect(MusicPlayerToken::findByToken($first)?->name)->toBe('obs')
        ->and($output)->toContain('Authorization: Bearer <token>')
        ->and($output)->not->toContain('?token=');

    $this->artisan('music:player-token', ['name' => 'obs'])
        ->expectsOutputToContain('already has a token')
        ->assertFailed();

    $this->artisan('music:player-token', ['name' => 'obs', '--rotate' => true])->assertSuccessful();
    expect(MusicPlayerToken::findByToken($first))->toBeNull()
        ->and(MusicPlayerToken::count())->toBe(1);

    $this->artisan('music:player-token', ['name' => 'obs', '--revoke' => true])->assertSuccessful();
    expect(MusicPlayerToken::count())->toBe(0);

    $this->artisan('music:player-token', ['name' => 'obs', '--revoke' => true])->assertFailed();
    $this->artisan('music:player-token', ['name' => 'Not A Slug!'])->assertExitCode(2);
});

test('only the token hash is stored', function () {
    $token = MusicPlayerToken::issue('obs');

    expect(MusicPlayerToken::sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(MusicPlayerToken::sole()->toArray())->not->toHaveKey('token_hash');
});
