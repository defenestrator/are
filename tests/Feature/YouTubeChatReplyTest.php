<?php

use App\Chat\ChatCommandRegistry;
use App\IdentityProvider;
use App\Jobs\PostChatReply;
use App\Models\ChatCommandRun;
use App\Models\LinkCode;
use App\Models\Question;
use App\Models\User;
use App\Models\YouTubeChannelToken;
use App\Models\YouTubeLiveChat;
use App\YouTube\Quota;
use App\YouTube\YouTubeApi;
use App\YouTube\YouTubeOAuthProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as OAuthUser;

const YT_CHANNEL = 'UCedosMainChannel00000000';
const YT_TOKEN = 'ya29.channel-owner-secret';
const YT_INSERT = 'www.googleapis.com/youtube/v3/liveChat/messages*';

beforeEach(function () {
    config([
        'chat.replies.youtube' => true,
        'chat.replies.youtube_per_stream' => 40,
        'services.youtube.api_key' => 'yt-test-key',
        'services.youtube.channel_ids' => [YT_CHANNEL],
        'services.youtube.quota.daily_units' => 10000,
        'services.youtube.quota.alert_ratio' => 0.8,
        'services.youtube.oauth.client_id' => 'google-client-id',
        'services.youtube.oauth.client_secret' => 'google-client-secret',
        'services.youtube.oauth.redirect' => 'https://are.test/youtube/broadcaster/callback',
    ]);

    $this->chat = YouTubeLiveChat::factory()->create(['channel_id' => YT_CHANNEL, 'live_chat_id' => 'LIVE-CHAT-1']);
});

function connectYouTube(array $attributes = []): YouTubeChannelToken
{
    return YouTubeChannelToken::create($attributes + [
        'channel_id' => YT_CHANNEL,
        'channel_title' => 'EDOS',
        'access_token' => YT_TOKEN,
        'refresh_token' => 'google-refresh-secret',
        'expires_at' => now()->addHour(),
        'scopes' => ['openid', YouTubeApi::POST_SCOPE],
    ]);
}

/** A liveChatMessage resource, as liveChatMessages.insert returns it. */
function ytInserted(): array
{
    return [YT_INSERT => Http::response(['kind' => 'youtube#liveChatMessage', 'id' => 'LCC.out-1', 'snippet' => ['type' => 'textMessageEvent']])];
}

function ytReplyJob(string $messageId = 'yt-src-1', string $reply = 'Hello YouTube'): PostChatReply
{
    ChatCommandRun::claim(IdentityProvider::YouTube, $messageId, 'q');

    return new PostChatReply(IdentityProvider::YouTube, YT_CHANNEL, $messageId, $reply, now());
}

function ytInserts(): int
{
    return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/liveChat/messages'))->count();
}

// --- Posting ------------------------------------------------------------------------

test('a YouTube !q gets its reply posted into the live chat as the channel owner', function () {
    connectYouTube();
    User::factory()->youtube('UCviewerYouTube0000000000')->create();
    Http::fake(ytInserted());

    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, YT_CHANNEL, 'UCviewerYouTube0000000000', 'Kale Fan', 'LCC.src-1', '!q sing about kale');

    $question = Question::sole();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://www.googleapis.com/youtube/v3/liveChat/messages?part=snippet'
        && $r->hasHeader('Authorization', 'Bearer '.YT_TOKEN)
        && ! $r->hasHeader('X-Goog-Api-Key')
        && $r['snippet']['liveChatId'] === 'LIVE-CHAT-1'
        && $r['snippet']['type'] === 'textMessageEvent'
        && str_contains($r['snippet']['textMessageDetails']['messageText'], "#{$question->id}"));

    expect(Quota::used(Quota::UNITS))->toBe(50)
        ->and($this->chat->fresh()->replies_sent)->toBe(1)
        ->and(ChatCommandRun::firstWhere('message_id', 'LCC.src-1')->reply_sent_at)->not->toBeNull();
});

test('replies longer than YouTube\'s 200 characters are cut', function () {
    connectYouTube();
    Http::fake(ytInserted());

    ytReplyJob(reply: str_repeat('é', 300))->handle();

    Http::assertSent(fn (Request $r) => mb_strlen($r['snippet']['textMessageDetails']['messageText']) === 200);
});

test('a retried or duplicated job posts once', function () {
    connectYouTube();
    Http::fake(ytInserted());

    $job = ytReplyJob();
    $job->handle();
    $job->handle();

    expect(ytInserts())->toBe(1)
        ->and(Quota::used(Quota::UNITS))->toBe(50);
});

test('an expiring token is refreshed first, and the new token is stored encrypted', function () {
    connectYouTube(['expires_at' => now()->addSeconds(30)]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3599, 'scope' => 'openid '.YouTubeApi::POST_SCOPE, 'token_type' => 'Bearer']),
    ] + ytInserted());

    ytReplyJob()->handle();

    Http::assertSent(fn (Request $r) => $r->url() === YouTubeApi::TOKEN_URL
        && $r['grant_type'] === 'refresh_token'
        && $r['refresh_token'] === 'google-refresh-secret'
        && $r['client_id'] === 'google-client-id');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/liveChat/messages') && $r->hasHeader('Authorization', 'Bearer ya29.fresh'));

    $raw = DB::table('youtube_channel_tokens')->first();
    expect($raw->access_token)->not->toContain('ya29.fresh')
        ->and(YouTubeChannelToken::sole()->access_token)->toBe('ya29.fresh')
        ->and(YouTubeChannelToken::sole()->refresh_token)->toBe('google-refresh-secret');
});

test('a refused refresh (a revoked or lapsed 7-day token) posts nothing, says to reconnect, and leaks no token', function () {
    connectYouTube(['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)] + ytInserted());
    Log::spy();

    ytReplyJob()->handle();

    expect(ytInserts())->toBe(0)
        ->and($this->chat->fresh()->replies_sent)->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'reconnect')
        && str_contains($message, 'invalid_grant')
        && ! str_contains($message.json_encode($context), 'google-refresh-secret')
        && ! str_contains($message.json_encode($context), YT_TOKEN));
});

test('a channel whose owner has not connected, or granted no posting scope, posts nothing', function (?array $scopes) {
    if ($scopes !== null) {
        connectYouTube(['scopes' => $scopes]);
    }
    Http::fake();
    Log::spy();

    ytReplyJob()->handle();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '/youtube/broadcaster/connect'));
})->with([
    'not connected' => [null],
    'read-only grant' => [['openid', 'https://www.googleapis.com/auth/youtube.readonly']],
]);

test('a channel ARE is not reading takes no replies', function () {
    connectYouTube();
    $this->chat->finish(YouTubeLiveChat::ENDED, 'offline');
    Http::fake();

    ytReplyJob()->handle();

    Http::assertNothingSent();
});

// --- Budgets ---------------------------------------------------------------------------

test('the per-stream reply budget is a hard cap', function () {
    config(['chat.replies.youtube_per_stream' => 2, 'chat.replies.per_channel' => 100]);
    connectYouTube();
    Http::fake(ytInserted());

    foreach (['a', 'b', 'c', 'd'] as $id) {
        ytReplyJob("yt-{$id}")->handle();
    }

    expect(ytInserts())->toBe(2)
        ->and($this->chat->fresh()->replies_sent)->toBe(2)
        ->and(Quota::used(Quota::UNITS))->toBe(100);
});

test('the budget is per stream: a new live chat starts again', function () {
    config(['chat.replies.youtube_per_stream' => 1]);
    connectYouTube();
    Http::fake(ytInserted());

    ytReplyJob('yt-a')->handle();
    $this->chat->finish(YouTubeLiveChat::ENDED, 'offline');
    YouTubeLiveChat::factory()->create(['video_id' => 'nextStream1', 'channel_id' => YT_CHANNEL, 'live_chat_id' => 'LIVE-CHAT-2']);
    ytReplyJob('yt-b')->handle();

    expect(ytInserts())->toBe(2);
    Http::assertSent(fn (Request $r) => ($r['snippet']['liveChatId'] ?? null) === 'LIVE-CHAT-2');
});

test('replies stop before they would take the day\'s units past the alert threshold', function () {
    connectYouTube();
    Http::fake(ytInserted());
    DB::table('youtube_quota_usage')->insert(['day' => Quota::day(), 'bucket' => Quota::UNITS, 'used' => 7960, 'calls' => 7960, 'failed_calls' => 0, 'created_at' => now(), 'updated_at' => now()]);
    Log::spy();

    ytReplyJob()->handle();

    expect(ytInserts())->toBe(0)
        ->and(Quota::used(Quota::UNITS))->toBe(7960);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'alert threshold'));
});

// --- Failures ----------------------------------------------------------------------------

test('a 5xx releases the claim and the budget slot, and the retry posts once', function () {
    connectYouTube();
    Http::fake([YT_INSERT => Http::sequence()->push(['error' => ['code' => 503]], 503)->push(['id' => 'LCC.out-1'])]);
    $job = ytReplyJob();

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect($this->chat->fresh()->replies_sent)->toBe(0)
        ->and(ChatCommandRun::sole()->reply_sent_at)->toBeNull();

    $job->handle();

    expect(ytInserts())->toBe(2)
        ->and($this->chat->fresh()->replies_sent)->toBe(1)
        // Both calls are billed, the failed one too.
        ->and(Quota::used(Quota::UNITS))->toBe(100)
        ->and(Quota::failedCalls(Quota::UNITS))->toBe(1);
});

test('a refused reply is logged with YouTube\'s reason, without the token, and not retried', function () {
    connectYouTube();
    Http::fake([YT_INSERT => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'liveChatEnded']]]], 403)]);
    Log::spy();

    ytReplyJob()->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'YouTube refused')
        && $context['reason'] === 'liveChatEnded'
        && ! str_contains(json_encode($context), YT_TOKEN));
    expect(Quota::used(Quota::UNITS))->toBe(50);
});

// --- Connecting a channel ------------------------------------------------------------------

test('only a broadcaster can connect a YouTube channel', function () {
    $this->actingAs(User::factory()->create())->get('/youtube/broadcaster/connect')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/youtube/broadcaster/callback')->assertForbidden();
});

test('connecting asks Google for youtube.force-ssl with offline access and forced consent', function () {
    $response = $this->actingAs(User::factory()->twitch('1000')->create())->get('/youtube/broadcaster/connect');

    $response->assertRedirect();
    $location = urldecode($response->headers->get('Location'));
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->toContain('client_id=google-client-id')
        ->toContain('redirect_uri=https://are.test/youtube/broadcaster/callback')
        ->toContain('scope=openid '.YouTubeApi::POST_SCOPE.' https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly')
        ->toContain('access_type=offline')
        ->toContain('prompt=consent');
});

function fakeGoogleGrant(array $scopes = ['openid', YouTubeApi::POST_SCOPE, ...YouTubeApi::ANALYTICS_SCOPES], ?string $refreshToken = 'granted-refresh'): void
{
    $account = (new OAuthUser)->setRaw(['sub' => 'google-sub'])->map(['id' => 'google-sub'])
        ->setToken('ya29.granted')
        ->setRefreshToken($refreshToken)
        ->setExpiresIn(3599)
        ->setApprovedScopes($scopes);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($account);
    Socialite::shouldReceive('buildProvider')->andReturn($provider);
}

function myChannelResponse(string $channelId = YT_CHANNEL): array
{
    return ['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['kind' => 'youtube#channel', 'id' => $channelId, 'snippet' => ['title' => 'EDOS']]]])];
}

test('the callback stores the channel owner\'s token, encrypted, keyed on the channel Google reports', function () {
    fakeGoogleGrant();
    Http::fake(myChannelResponse());
    $broadcaster = User::factory()->twitch('1000')->create();

    $this->actingAs($broadcaster)->get('/youtube/broadcaster/callback')->assertRedirect('/vote');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/channels') && $r['mine'] === 'true' && $r->hasHeader('Authorization', 'Bearer ya29.granted'));
    $token = YouTubeChannelToken::sole();
    expect($token->channel_id)->toBe(YT_CHANNEL)
        ->and($token->channel_title)->toBe('EDOS')
        ->and($token->access_token)->toBe('ya29.granted')
        ->and($token->refresh_token)->toBe('granted-refresh')
        ->and($token->canPost())->toBeTrue()
        ->and($token->connected_by)->toBe($broadcaster->id)
        ->and(DB::table('youtube_channel_tokens')->value('refresh_token'))->not->toContain('granted-refresh')
        ->and(Quota::used(Quota::UNITS))->toBe(1);
});

test('the callback refuses a channel this app does not serve, a grant without posting, and a grant with no refresh token', function (string $case) {
    match ($case) {
        'foreign channel' => [fakeGoogleGrant(), Http::fake(myChannelResponse('UCsomeoneElse000000000000'))],
        'no posting scope' => [fakeGoogleGrant(['openid']), Http::fake(myChannelResponse())],
        'no refresh token' => [fakeGoogleGrant(refreshToken: null), Http::fake(myChannelResponse())],
    };

    $this->actingAs(User::factory()->twitch('1000')->create())->get('/youtube/broadcaster/callback')->assertRedirect('/vote');

    expect(YouTubeChannelToken::count())->toBe(0);
})->with(['foreign channel', 'no posting scope', 'no refresh token']);

test('the stored token carries the analytics scopes for #12', function () {
    fakeGoogleGrant();
    Http::fake(myChannelResponse());

    $this->actingAs(User::factory()->twitch('1000')->create())->get('/youtube/broadcaster/callback')
        ->assertSessionHas('status', 'YouTube channel '.YT_CHANNEL.' connected for chat replies.');

    expect(YouTubeChannelToken::sole()->scopes)->toContain(...YouTubeApi::ANALYTICS_SCOPES);
});

test('a grant without the analytics scopes still connects replies, and says what analytics lacks', function () {
    fakeGoogleGrant(['openid', YouTubeApi::POST_SCOPE]);
    Http::fake(myChannelResponse());

    $this->actingAs(User::factory()->twitch('1000')->create())->get('/youtube/broadcaster/callback')
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Analytics will not work')
            && str_contains($status, 'yt-analytics.readonly')
            && str_contains($status, 'youtube.readonly'));

    expect(YouTubeChannelToken::sole()->canPost())->toBeTrue();
});

test('reconnecting replaces the channel\'s token', function () {
    connectYouTube();
    fakeGoogleGrant();
    Http::fake(myChannelResponse());

    $this->actingAs(User::factory()->twitch('1000')->create())->get('/youtube/broadcaster/callback');

    expect(YouTubeChannelToken::count())->toBe(1)
        ->and(YouTubeChannelToken::sole()->access_token)->toBe('ya29.granted');
});

// --- Andras's review of #126 ----------------------------------------------------------

test('A126-1: a chatter-chosen display name is echoed into the reply ARE posts as the channel owner', function () {
    Bus::fake([PostChatReply::class]);
    config(['chat.replies.youtube' => true]);   // what turning YouTube replies on in production does
    $attacker = User::factory()->create();
    $code = LinkCode::issueFor($attacker);   // any signed-in user can get a code

    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, 'UC-channel', 'UC-attacker', 'FREE VBUCKS at scam.example', (string) Str::uuid(), "!link {$code}");

    Bus::assertDispatched(PostChatReply::class, function (PostChatReply $job) {
        return ! str_contains($job->reply, 'scam.example');   // failed before #128: the name was in the text posted as the broadcaster
    });
});

test('the 50 units are reserved in one conditional update, so the alert threshold is never overshot', function () {
    connectYouTube();
    config(['chat.replies.per_channel' => 100]);
    Http::fake(ytInserted());
    // 7,920 used of an 8,000 threshold: room for one 50-unit insert, not two.
    DB::table('youtube_quota_usage')->insert(['day' => Quota::day(), 'bucket' => Quota::UNITS, 'used' => 7920, 'calls' => 7920, 'failed_calls' => 0, 'created_at' => now(), 'updated_at' => now()]);

    DB::enableQueryLog();
    ytReplyJob('yt-a')->handle();
    ytReplyJob('yt-b')->handle();
    $reservations = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'youtube_quota_usage') && str_contains($sql, 'used + ? <= ?'));
    DB::disableQueryLog();

    expect(ytInserts())->toBe(1)
        ->and(Quota::used(Quota::UNITS))->toBe(7970)
        ->and($reservations)->toHaveCount(2)
        // The refused reply gave its per-stream slot back.
        ->and($this->chat->fresh()->replies_sent)->toBe(1);
});

test('a reply whose response timed out after sending is not retried, so it cannot post twice', function () {
    connectYouTube();
    Http::fake([YT_INSERT => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received')]);
    Log::spy();
    $job = ytReplyJob();

    $job->handle(); // does not throw, so the queue does not retry it

    expect(ChatCommandRun::sole()->reply_sent_at)->not->toBeNull()
        ->and($this->chat->fresh()->replies_sent)->toBe(1)
        ->and(Quota::failedCalls(Quota::UNITS))->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'not retried'));
});

test('a reply that could not connect at all is retried, and posts once', function (string $error) {
    connectYouTube();
    Http::fake([YT_INSERT => Http::sequence()
        ->pushResponse(fn () => throw new ConnectionException($error))
        ->push(['id' => 'LCC.out-1'])]);
    $job = ytReplyJob();

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(ChatCommandRun::sole()->reply_sent_at)->toBeNull()
        ->and($this->chat->fresh()->replies_sent)->toBe(0);

    $job->handle();
    expect($this->chat->fresh()->replies_sent)->toBe(1);
})->with([
    'connect timeout' => ['cURL error 28: Connection timed out after 5001 milliseconds'],
    'refused' => ['cURL error 7: Failed to connect to www.googleapis.com port 443: Connection refused'],
    'DNS' => ['cURL error 6: Could not resolve host: www.googleapis.com'],
]);

test('no channel can be connected while YOUTUBE_CHANNEL_IDS is empty', function () {
    config(['services.youtube.channel_ids' => []]);
    fakeGoogleGrant();
    Http::fake(myChannelResponse());
    $broadcaster = User::factory()->twitch('1000')->create();

    $this->actingAs($broadcaster)->get('/youtube/broadcaster/connect')
        ->assertRedirect('/vote')
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'YOUTUBE_CHANNEL_IDS'));
    $this->actingAs($broadcaster)->get('/youtube/broadcaster/callback')->assertRedirect('/vote');

    expect(YouTubeChannelToken::count())->toBe(0);
    Http::assertNothingSent();
});

test('the YouTube connect keeps its OAuth state apart from the Twitch connect', function () {
    $this->actingAs(User::factory()->twitch('1000')->create())
        ->withSession(['state' => 'twitch-tab-state'])
        ->get('/youtube/broadcaster/connect');

    expect(session('state'))->toBe('twitch-tab-state')
        ->and(session(YouTubeOAuthProvider::STATE_KEY))->toBeString()->not->toBe('twitch-tab-state');
});
