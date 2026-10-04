<?php

use App\IdentityProvider;
use App\Jobs\RefreshTwitchSubscriptions;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\Twitch;
use App\TwitchSubscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
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

test('the retry job is unique per user and backs off', function () {
    $job = new RefreshTwitchSubscriptions(7);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('7')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600]);
});
