<?php

use App\Exceptions\TwitchTokenRejected;
use App\IdentityProvider;
use App\Jobs\RefreshTwitchSubscriptions;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\Twitch;
use App\TwitchSubscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\TwitchProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.twitch.broadcaster_id' => '1000',
        'services.twitch.broadcaster_ids' => ['2000'],
        'services.twitch.friend_ids' => [],
    ]);
});

function twitchLoginReturns(string $id = '42', string $token = 'viewer-token'): void
{
    $account = (new SocialiteUser)->map(['id' => $id, 'name' => 'Viewer', 'nickname' => 'Viewer', 'email' => null, 'avatar' => null])
        ->setToken($token)->setRefreshToken('refresh')->setExpiresIn(3600);
    $provider = Mockery::mock(TwitchProvider::class);
    $provider->shouldReceive('user')->andReturn($account);
    Socialite::shouldReceive('driver')->with('twitch')->andReturn($provider);
}

// Andras's repro (#32), with the agreed semantics: unknown, not None.

test('one unreachable channel does not break the subscription check', function () {
    Http::fake([
        '*broadcaster_id=1000*' => Http::response(['data' => [['tier' => '1000']]]),
        '*broadcaster_id=2000*' => Http::failedConnection(),
    ]);

    $subs = Twitch::checkUserSubscriptions('token', ['1000', '2000'], '42');

    expect($subs->get('1000'))->toBe(TwitchSubscription::Tier1)
        ->and($subs->has('2000'))->toBeTrue()
        ->and($subs->get('2000'))->toBeNull();
});

test('a failed lookup is logged without the token', function () {
    Log::spy();
    Http::fake(['*' => Http::failedConnection()]);

    Twitch::checkUserSubscriptions('secret-viewer-token', ['1000'], '42');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'unknown')
        && $context['broadcaster_id'] === '1000'
        && ! str_contains(json_encode($context), 'secret-viewer-token'));
});

test('Helix 404 means not subscribed, and a 5xx or a connection failure means unknown', function () {
    Http::fake([
        '*broadcaster_id=1000*' => Http::response(['error' => 'Not Found'], 404),
        '*broadcaster_id=2000*' => Http::response('', 503),
    ]);

    $subs = Twitch::checkUserSubscriptions('token', ['1000', '2000'], '42');

    expect($subs->get('1000'))->toBe(TwitchSubscription::None)
        ->and($subs->get('2000'))->toBeNull();
});

test('connection failures and 5xx are retried once; 4xx answers are not', function () {
    // Count every attempt here: Http::recorded() misses attempts that never got a response.
    $attempts = ['1000' => 0, '2000' => 0, '3000' => 0];
    Http::fake(function (Request $request) use (&$attempts) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $channel = $query['broadcaster_id'];
        $attempts[$channel]++;

        return match ($channel) {
            '1000' => $attempts['1000'] === 1 ? Http::failedConnection() : Http::response(['data' => [['tier' => '2000']]]),
            '2000' => Http::response(['error' => 'Unauthorized'], 401),
            '3000' => Http::response('', 502),
        };
    });

    $subs = Twitch::checkUserSubscriptions('token', ['1000', '2000', '3000'], '42');

    expect($subs->get('1000'))->toBe(TwitchSubscription::Tier2)
        ->and($attempts['1000'])->toBe(2)
        ->and($subs->get('2000'))->toBeNull()
        ->and($attempts['2000'])->toBe(1)
        ->and($subs->get('3000'))->toBeNull()
        ->and($attempts['3000'])->toBe(2);
});

test('syncing keeps a stored tier when its lookup fails, and stores the ones Helix answered', function () {
    $user = User::factory()->twitch('42')->create();
    UserTwitchSubscription::create(['user_id' => $user->id, 'broadcaster_id' => '2000', 'twitch_subscription' => TwitchSubscription::Tier3]);
    Http::fake([
        '*broadcaster_id=1000*' => Http::response(['data' => [['tier' => '1000']]]),
        '*broadcaster_id=2000*' => Http::failedConnection(),
    ]);

    expect(Twitch::syncUserSubscriptions($user, 'token', '42'))->toBe(['2000']);

    expect(UserTwitchSubscription::where('user_id', $user->id)->orderBy('broadcaster_id')->pluck('twitch_subscription', 'broadcaster_id')->all())
        ->toBe(['1000' => TwitchSubscription::Tier1, '2000' => TwitchSubscription::Tier3]);
});

// Sign-in

test('Twitch sign-in succeeds when Helix cannot be reached, and queues a retry', function () {
    Queue::fake();
    Http::fake(['api.twitch.tv/*' => Http::failedConnection()]);
    twitchLoginReturns();

    $this->get('/twitch/auth')->assertRedirect('/vote');

    $user = User::sole();
    $this->assertAuthenticatedAs($user);
    Queue::assertPushed(RefreshTwitchSubscriptions::class, fn ($job) => $job->userId === $user->id && $job->delay !== null);
});

test('Twitch sign-in succeeds even if the subscription sync throws', function () {
    Queue::fake();
    Http::fake(fn () => throw new RuntimeException('boom'));
    twitchLoginReturns();

    $this->get('/twitch/auth')->assertRedirect('/vote');

    $this->assertAuthenticated();
    Queue::assertPushed(RefreshTwitchSubscriptions::class);
});

test('a sign-in where every lookup answers queues nothing', function () {
    Queue::fake();
    Http::fake(['api.twitch.tv/*' => Http::response(['data' => []])]);
    twitchLoginReturns();

    $this->get('/twitch/auth')->assertRedirect('/vote');

    Queue::assertNothingPushed();
    expect(User::sole()->getHighestSubscription())->toBe(TwitchSubscription::None);
});

test('linking Twitch while Helix is down still links, and queues a retry', function () {
    Queue::fake();
    Http::fake(['api.twitch.tv/*' => Http::failedConnection()]);
    $user = User::factory()->facebook('fb-1')->create();
    twitchLoginReturns('42');

    $this->actingAs($user)->withSession(['identities.linking' => 'twitch'])->get('/twitch/auth')
        ->assertRedirect(route('settings'));

    expect($user->fresh()->twitch_id)->toBe('42');
    Queue::assertPushed(RefreshTwitchSubscriptions::class, fn ($job) => $job->userId === $user->id);
});

// The retry job

test('the retry job stores the tiers once Helix answers', function () {
    $user = User::factory()->twitch('42')->create();
    $user->identityFor(IdentityProvider::Twitch)->update(['access_token' => 'stored-token']);
    Http::fake(['api.twitch.tv/*' => Http::response(['data' => [['tier' => '3000']]])]);

    (new RefreshTwitchSubscriptions($user->id))->handle();

    expect($user->fresh()->getHighestSubscription())->toBe(TwitchSubscription::Tier3);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer stored-token'));
});

test('the retry job throws to be retried while a lookup still fails', function () {
    $user = User::factory()->twitch('42')->create();
    $user->identityFor(IdentityProvider::Twitch)->update(['access_token' => 'stored-token']);
    Http::fake(['api.twitch.tv/*' => Http::failedConnection()]);

    expect(fn () => (new RefreshTwitchSubscriptions($user->id))->handle())
        ->toThrow(RuntimeException::class, 'still failing');
});

test('the retry job does nothing without a Twitch identity or a stored token', function () {
    Http::fake();
    $facebookOnly = User::factory()->facebook('fb-1')->create();
    $noToken = User::factory()->twitch('42')->create();

    (new RefreshTwitchSubscriptions($facebookOnly->id))->handle();
    (new RefreshTwitchSubscriptions($noToken->id))->handle();
    (new RefreshTwitchSubscriptions(999999))->handle();

    Http::assertNothingSent();
});

// Refreshing an expired viewer token before the retry (#83)

function expiredTwitchViewer(): User
{
    $user = User::factory()->twitch('42')->create();
    $user->identityFor(IdentityProvider::Twitch)->update([
        'access_token' => 'expired-token',
        'refresh_token' => 'old-refresh-token',
        'token_expires_at' => now()->subHour(),
    ]);

    return $user;
}

test('the retry job refreshes an expired token, stores it encrypted, and syncs with it', function () {
    $user = expiredTwitchViewer();
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 14400,
            'scope' => ['user:read:subscriptions'],
            'token_type' => 'bearer',
        ]),
        'api.twitch.tv/*' => Http::response(['data' => [['tier' => '2000']]]),
    ]);

    (new RefreshTwitchSubscriptions($user->id))->handle();

    $identity = $user->identityFor(IdentityProvider::Twitch)->fresh();
    $raw = DB::table('identities')->where('id', $identity->id)->first();

    expect($user->fresh()->getHighestSubscription())->toBe(TwitchSubscription::Tier2)
        ->and($identity->access_token)->toBe('fresh-token')
        ->and($identity->refresh_token)->toBe('new-refresh-token')
        ->and($identity->token_expires_at->isFuture())->toBeTrue()
        ->and($raw->access_token)->not->toContain('fresh-token')
        ->and($raw->refresh_token)->not->toContain('new-refresh-token');

    Http::assertSent(fn (Request $request) => $request->url() === Twitch::TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh-token'
        && $request['client_id'] === config('services.twitch.client_id'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'subscriptions/user')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer expired-token'));
});

test('a rejected refresh gives up cleanly: no retry, tokens cleared, nothing secret logged', function () {
    Log::spy();
    $user = expiredTwitchViewer();
    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response(['status' => 400, 'message' => 'Invalid refresh token'], 400),
        'api.twitch.tv/*' => Http::response(['data' => [['tier' => '2000']]]),
    ]);

    (new RefreshTwitchSubscriptions($user->id))->handle();   // returns, does not throw

    $identity = $user->identityFor(IdentityProvider::Twitch)->fresh();
    expect($identity->access_token)->toBeNull()
        ->and($identity->refresh_token)->toBeNull()
        ->and($user->fresh()->getHighestSubscription())->toBe(TwitchSubscription::None);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.twitch.tv'));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'rejected')
        && $context['user_id'] === $user->id
        && $context['reason'] === 'HTTP 400'
        && ! str_contains(json_encode($context), 'refresh-token')
        && ! str_contains(json_encode($context), 'expired-token'));

    // With the tokens cleared, a later attempt makes no request at all.
    Http::fake();
    (new RefreshTwitchSubscriptions($user->id))->handle();
    Http::assertNothingSent();
});

test('a refresh that fails transiently throws so the job retries, keeping the tokens', function (Closure $response, string $exception) {
    $user = expiredTwitchViewer();
    Http::fake(['id.twitch.tv/oauth2/token' => $response()]);

    expect(fn () => (new RefreshTwitchSubscriptions($user->id))->handle())->toThrow($exception);

    expect($user->identityFor(IdentityProvider::Twitch)->fresh()->refresh_token)->toBe('old-refresh-token');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.twitch.tv'));
})->with([
    'a 5xx' => [fn () => Http::response('', 503), RuntimeException::class],
    'no connection' => [fn () => Http::failedConnection(), ConnectionException::class],
]);

test('a token that has not expired is used as is, without a refresh', function () {
    $user = User::factory()->twitch('42')->create();
    $user->identityFor(IdentityProvider::Twitch)->update([
        'access_token' => 'live-token',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHours(3),
    ]);
    Http::fake(['api.twitch.tv/*' => Http::response(['data' => [['tier' => '1000']]])]);

    (new RefreshTwitchSubscriptions($user->id))->handle();

    Http::assertNotSent(fn (Request $request) => $request->url() === Twitch::TOKEN_URL);
    expect($user->fresh()->getHighestSubscription())->toBe(TwitchSubscription::Tier1);
});

// A token Helix rejects before its stored expiry (#106)

function liveTwitchViewer(): User
{
    $user = User::factory()->twitch('42')->create();
    $user->identityFor(IdentityProvider::Twitch)->update([
        'access_token' => 'revoked-token',
        'refresh_token' => 'old-refresh-token',
        'token_expires_at' => now()->addHours(3),   // not expired by the clock
    ]);

    return $user;
}

/**
 * Helix answers 401 to the revoked token, and to the refreshed one when $stillRejected.
 *
 * @param  array{refreshes: int}  $counts
 */
function fakeTwitchWithRevokedToken(array &$counts, ?Closure $refresh = null, bool $stillRejected = false): void
{
    $refresh ??= fn () => Http::response(['access_token' => 'fresh-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 14400]);

    Http::fake(function (Request $request) use (&$counts, $refresh, $stillRejected) {
        if ($request->url() === Twitch::TOKEN_URL) {
            $counts['refreshes']++;

            return $refresh();
        }

        return $request->hasHeader('Authorization', 'Bearer revoked-token') || $stillRejected
            ? Http::response(['error' => 'Unauthorized', 'status' => 401, 'message' => 'Invalid OAuth token'], 401)
            : Http::response(['data' => [['tier' => '2000']]]);
    });
}

test('a 401 refreshes the token once and the retried sync succeeds', function () {
    $user = liveTwitchViewer();
    $counts = ['refreshes' => 0];
    fakeTwitchWithRevokedToken($counts);

    (new RefreshTwitchSubscriptions($user->id))->handle();

    $identity = $user->identityFor(IdentityProvider::Twitch)->fresh();
    expect($counts['refreshes'])->toBe(1)
        ->and($identity->access_token)->toBe('fresh-token')
        ->and($identity->refresh_token)->toBe('new-refresh-token')
        ->and($user->fresh()->getHighestSubscription())->toBe(TwitchSubscription::Tier2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'subscriptions/user')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

test('a 401 followed by a rejected refresh clears the tokens and stops', function () {
    $user = liveTwitchViewer();
    $counts = ['refreshes' => 0];
    fakeTwitchWithRevokedToken($counts, fn () => Http::response(['status' => 400, 'message' => 'Invalid refresh token'], 400));

    (new RefreshTwitchSubscriptions($user->id))->handle();   // returns, does not throw

    $identity = $user->identityFor(IdentityProvider::Twitch)->fresh();
    expect($counts['refreshes'])->toBe(1)
        ->and($identity->access_token)->toBeNull()
        ->and($identity->refresh_token)->toBeNull();
});

test('a token still rejected after one refresh gives up without another refresh', function () {
    Log::spy();
    $user = liveTwitchViewer();
    $counts = ['refreshes' => 0];
    fakeTwitchWithRevokedToken($counts, stillRejected: true);

    (new RefreshTwitchSubscriptions($user->id))->handle();   // returns, does not throw

    expect($counts['refreshes'])->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'even after a refresh')
        && $context['user_id'] === $user->id
        && ! str_contains(json_encode($context), 'token'));
});

test('a 401 on one channel keeps the tiers the others answered, then reports the dead token', function () {
    $user = User::factory()->twitch('42')->create();
    Http::fake([
        '*broadcaster_id=1000*' => Http::response(['data' => [['tier' => '1000']]]),
        '*broadcaster_id=2000*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    expect(fn () => Twitch::syncUserSubscriptions($user, 'revoked-token', '42'))
        ->toThrow(TwitchTokenRejected::class);

    expect(UserTwitchSubscription::where('user_id', $user->id)->pluck('twitch_subscription', 'broadcaster_id')->all())
        ->toBe(['1000' => TwitchSubscription::Tier1]);
});

test('the unguarded single-channel checkUserSubscription is gone', function () {
    expect(method_exists(Twitch::class, 'checkUserSubscription'))->toBeFalse();
});

test('the retry job is unique per user and backs off', function () {
    $job = new RefreshTwitchSubscriptions(7);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('7')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600]);
});
