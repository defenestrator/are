<?php

use App\Enums\SongRequestSource;
use App\Enums\SongRequestStatus;
use App\Jobs\EventSub\HandleChannelPointRedemption;
use App\Jobs\RefundChannelPointRedemption;
use App\Models\BroadcasterToken;
use App\Models\ChannelPointRedemption;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\TwitchModerator;
use App\Models\User;
use App\SongRequests;
use App\Twitch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const REFUND_REWARD = 'reward-refund-0001';
const REFUND_TOKEN = 'refund-broadcaster-token';
const REDEMPTIONS_URL = 'api.twitch.tv/helix/channel_points/custom_rewards/redemptions*';

beforeEach(function () {
    config([
        'services.twitch.broadcaster_id' => '1000',
        'music.song_request_reward_id' => REFUND_REWARD,
    ]);

    BroadcasterToken::create([
        'broadcaster_id' => '1000',
        'access_token' => REFUND_TOKEN,
        'refresh_token' => 'refresh-secret',
        'expires_at' => now()->addHour(),
        'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);
});

function redeemForRefund(string $input, array $overrides = []): ChannelPointRedemption
{
    $id = $overrides['id'] ?? (string) Str::uuid();

    (new HandleChannelPointRedemption((string) Str::uuid(), now()->toIso8601ZuluString(), $overrides + [
        'id' => $id,
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'user_id' => '6660001',
        'user_login' => 'pointspender',
        'user_name' => 'PointSpender',
        'user_input' => $input,
        'status' => 'unfulfilled',
        'reward' => ['id' => REFUND_REWARD, 'title' => 'Request a song', 'cost' => 500, 'prompt' => 'Song'],
        'redeemed_at' => now()->toIso8601ZuluString(),
    ]))->handle();

    return ChannelPointRedemption::where('twitch_redemption_id', $id)->sole();
}

function refundJob(ChannelPointRedemption $redemption): RefundChannelPointRedemption
{
    return new RefundChannelPointRedemption($redemption->id, 'test');
}

// --- Dispatch ----------------------------------------------------------------

test('a refused song redemption is refunded through Helix', function () {
    Http::fake([REDEMPTIONS_URL => Http::response(['data' => [['id' => 'x', 'status' => 'CANCELED']]])]);

    $redemption = redeemForRefund('no such song');

    expect(SongRequest::count())->toBe(0)
        ->and($redemption->status)->toBe('canceled');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($redemption) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'PATCH'
            && str_starts_with($request->url(), 'https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?')
            && $query === ['broadcaster_id' => '1000', 'reward_id' => REFUND_REWARD, 'id' => $redemption->twitch_redemption_id]
            && $request->data() === ['status' => 'CANCELED']
            && $request->hasHeader('Authorization', 'Bearer '.REFUND_TOKEN);
    });
});

test('the refund runs on the queue with the refusal as its reason', function () {
    Queue::fake();

    $redemption = redeemForRefund('no such song');

    Queue::assertPushed(RefundChannelPointRedemption::class, fn ($job) => $job->redemptionId === $redemption->id
        && str_contains($job->reason, 'No requestable song matches'));
});

test('accepted redemptions and other rewards are not refunded', function () {
    Http::fake();
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    redeemForRefund('Midnight Tea');
    redeemForRefund('anything', ['reward' => ['id' => 'other-reward', 'title' => 'Hydrate', 'cost' => 100]]);

    expect(SongRequest::count())->toBe(1);
    Http::assertNothingSent();
});

test('any listed reward id is a song reward, one per channel', function () {
    Http::fake([REDEMPTIONS_URL => Http::response(['data' => []])]);
    config(['music.song_request_reward_id' => 'reward-on-another-channel, '.REFUND_REWARD]);
    Track::factory()->streamSafe()->create(['title' => 'Midnight Tea']);

    redeemForRefund('Midnight Tea');

    expect(SongRequest::count())->toBe(1);
});

// --- Idempotency -------------------------------------------------------------

test('running the refund twice sends one PATCH', function () {
    Queue::fake();
    Http::fake([REDEMPTIONS_URL => Http::response(['data' => []])]);
    $redemption = redeemForRefund('no such song');

    refundJob($redemption)->handle();
    refundJob($redemption)->handle();

    Http::assertSentCount(1);
    expect($redemption->refresh()->status)->toBe('canceled');
});

test('Twitch saying the redemption is already resolved is final, and not sent again', function () {
    Queue::fake();
    Http::fake([REDEMPTIONS_URL => Http::response(['status' => 422, 'message' => 'already fulfilled or canceled'], 422)]);
    $redemption = redeemForRefund('no such song');

    refundJob($redemption)->handle();
    refundJob($redemption)->handle();

    Http::assertSentCount(1);
    expect($redemption->refresh()->status)->toBe('unknown');
});

// --- Failures ----------------------------------------------------------------

test('rate limits, server errors and connection failures release the claim and retry', function (Closure $failure) {
    Queue::fake();
    $responses = [$failure(), Http::response(['data' => []])];
    Http::fake([REDEMPTIONS_URL => function () use (&$responses) {
        return array_shift($responses);
    }]);
    $redemption = redeemForRefund('no such song');

    expect(fn () => refundJob($redemption)->handle())->toThrow(RuntimeException::class, 'retrying');
    expect($redemption->refresh()->status)->toBe('unfulfilled');

    // The retry succeeds.
    refundJob($redemption)->handle();
    expect($redemption->refresh()->status)->toBe('canceled');
    Http::assertSentCount(2);
})->with([
    '429' => fn () => fn () => Http::response(['message' => 'slow down'], 429),
    '503' => fn () => fn () => Http::response('', 503),
    'connection' => fn () => fn () => Http::failedConnection(),
]);

test('401, 403 and 404 are logged with a hint, without the token, and not retried', function (int $status, string $hint) {
    Queue::fake();
    Http::fake([REDEMPTIONS_URL => Http::response(['status' => $status, 'message' => 'nope'], $status)]);
    $redemption = redeemForRefund('no such song');
    Log::spy();

    refundJob($redemption)->handle();

    expect($redemption->refresh()->status)->toBe('unfulfilled');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, $hint)
        && $context['status'] === $status
        && ! str_contains(json_encode($context), REFUND_TOKEN))->once();
})->with([
    '401' => [401, '/twitch/broadcaster/connect'],
    '403' => [403, 'music:create-song-reward'],
    '404' => [404, 'music:create-song-reward'],
]);

test('a token without channel:manage:redemptions sends nothing and says to reconnect', function () {
    Queue::fake();
    Http::fake();
    BroadcasterToken::where('broadcaster_id', '1000')->update(['scopes' => array_values(array_diff(Twitch::BROADCASTER_SCOPES, ['channel:manage:redemptions']))]);
    $redemption = redeemForRefund('no such song');
    Log::spy();

    refundJob($redemption)->handle();

    Http::assertNothingSent();
    expect($redemption->refresh()->status)->toBe('unfulfilled');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'lacks channel:manage:redemptions'))->once();
});

test('the broadcaster connection asks for channel:manage:redemptions', function () {
    expect(Twitch::BROADCASTER_SCOPES)->toContain('channel:manage:redemptions');
});

// --- music:create-song-reward ------------------------------------------------

test('music:create-song-reward creates a reward that requires user input and prints its id', function () {
    Http::fake([
        'api.twitch.tv/helix/channel_points/custom_rewards?*' => Http::sequence()
            ->push(['data' => [['id' => 'r-old', 'title' => 'Hydrate']]])
            ->push(['data' => [['id' => 'r-new', 'title' => 'Request a song']]]),
    ]);

    $this->artisan('music:create-song-reward', ['--cost' => 750])
        ->expectsOutputToContain('Created the "Request a song" reward (750 points) on 1000.')
        ->expectsOutputToContain('Reward id: r-new')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), 'only_manageable_rewards=true'));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), 'broadcaster_id=1000')
        && $request['title'] === 'Request a song'
        && $request['cost'] === 750
        && $request['is_user_input_required'] === true);
});

test('music:create-song-reward reuses a matching reward instead of creating a duplicate', function () {
    Http::fake([
        'api.twitch.tv/helix/channel_points/custom_rewards?*' => Http::response(['data' => [['id' => 'r-existing', 'title' => 'request a SONG']]]),
    ]);

    $this->artisan('music:create-song-reward')
        ->expectsOutputToContain('Reward id: r-existing')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('music:create-song-reward explains Twitch refusals and validates its options', function () {
    Http::fake([
        'api.twitch.tv/helix/channel_points/custom_rewards?*' => Http::sequence()
            ->push(['data' => []])
            ->push(['status' => 403, 'message' => 'The broadcaster must have Partner or Affiliate status to create custom rewards.'], 403),
    ]);

    $this->artisan('music:create-song-reward')
        ->expectsOutputToContain('Twitch refused to create the reward (403)')
        ->expectsOutputToContain('Partner or Affiliate')
        ->assertFailed();

    $this->artisan('music:create-song-reward', ['--title' => str_repeat('x', 46)])->assertFailed();
    $this->artisan('music:create-song-reward', ['--cost' => 0])->assertFailed();
    $this->artisan('music:create-song-reward', ['--broadcaster' => '424242'])
        ->expectsOutputToContain('424242 is not a broadcaster this app serves.')
        ->assertFailed();
});

// --- Skipped or cleared before playing (#133) --------------------------------

function refundModerator(): User
{
    $mod = User::factory()->twitch()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

/** A queued channel-point request for a fresh stream-safe track. */
function paidRequest(): SongRequest
{
    $track = Track::factory()->streamSafe()->create();

    return SongRequest::where('channel_point_redemption_id', redeemForRefund((string) $track->id)->id)->sole();
}

test('skipping a channel-point request before it plays refunds it once', function () {
    Http::fake([REDEMPTIONS_URL => Http::response(['data' => []])]);
    $mod = refundModerator();
    $request = paidRequest();
    Http::assertNothingSent();

    SongRequests::skip($mod, $request);

    expect(fn () => SongRequests::skip($mod, $request))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && str_contains($r->url(), 'id='.$request->redemption()->value('twitch_redemption_id'))
        && $r->data() === ['status' => 'CANCELED']);
    expect(ChannelPointRedemption::find($request->channel_point_redemption_id)->status)->toBe('canceled')
        ->and($request->refresh()->status)->toBe(SongRequestStatus::Skipped);
});

test('clearing the queue refunds each waiting channel-point request, and no chat request', function () {
    Queue::fake();
    $mod = refundModerator();
    $paid = [paidRequest(), paidRequest()];
    SongRequest::factory()->create(); // a chat request

    expect(SongRequests::clear($mod))->toBe(3);

    Queue::assertPushedTimes(RefundChannelPointRedemption::class, 2);
    foreach ($paid as $request) {
        Queue::assertPushed(RefundChannelPointRedemption::class, fn ($job) => $job->redemptionId === $request->channel_point_redemption_id
            && str_contains($job->reason, 'cleared'));
    }
});

test('a request that has played or is on air is not refunded', function () {
    Queue::fake();
    $mod = refundModerator();
    $played = paidRequest();
    $onAir = paidRequest();

    SongRequests::play($mod, $played);
    SongRequests::play($mod, $onAir); // $played is now played
    SongRequests::skip($mod, $onAir);
    SongRequests::clear($mod);

    Queue::assertNotPushed(RefundChannelPointRedemption::class);
    expect($played->refresh()->status)->toBe(SongRequestStatus::Played)
        ->and($onAir->refresh()->status)->toBe(SongRequestStatus::Skipped);
});

test('skipping a chat request refunds nothing', function () {
    Queue::fake();
    $request = SongRequest::factory()->create(['source' => SongRequestSource::Chat]);

    SongRequests::skip(refundModerator(), $request);

    Queue::assertNotPushed(RefundChannelPointRedemption::class);
});

test('no refund is sent if the skip is rolled back', function () {
    Http::fake([REDEMPTIONS_URL => Http::response(['data' => []])]);
    $mod = refundModerator();
    $request = paidRequest();

    try {
        DB::transaction(function () use ($mod, $request) {
            SongRequests::skip($mod, $request);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Http::assertNothingSent();
    expect($request->refresh()->status)->toBe(SongRequestStatus::Queued);
});
