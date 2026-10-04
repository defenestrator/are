<?php

use App\Chat\ChatCommandRegistry;
use App\IdentityProvider;
use App\Jobs\PostChatReply;
use App\Models\BroadcasterToken;
use App\Models\ChatCommandRun;
use App\Models\StreamSession;
use App\Twitch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const REPLY_TOKEN = 'broadcaster-secret-token';

beforeEach(function () {
    BroadcasterToken::create([
        'broadcaster_id' => '1000',
        'access_token' => REPLY_TOKEN,
        'refresh_token' => 'refresh-secret',
        'expires_at' => now()->addHour(),
        'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);
});

function helixSent(): array
{
    return [
        'api.twitch.tv/helix/chat/messages' => Http::response(['data' => [['message_id' => 'out-1', 'is_sent' => true, 'drop_reason' => null]]]),
    ];
}

/** A claimed source message and its reply job, as the registry would make them. */
function replyJob(string $messageId = 'src-1', string $reply = 'Hello chat', string $channelId = '1000', ?Carbon $at = null): PostChatReply
{
    ChatCommandRun::claim(IdentityProvider::Twitch, $messageId, 'edos');

    return new PostChatReply(IdentityProvider::Twitch, $channelId, $messageId, $reply, $at ?? now());
}

function chatRegistryRun(string $text, string $messageId = 'src-1', IdentityProvider $provider = IdentityProvider::Twitch, string $channelId = '1000'): void
{
    app(ChatCommandRegistry::class)->run($provider, $channelId, '4145994', 'viewer32', $messageId, $text);
}

test('a command reply is posted to Twitch as the broadcaster, replying to the source message', function () {
    Http::fake(helixSent());
    StreamSession::factory()->create(['twitch_stream_id' => '777']);

    chatRegistryRun('!edos', 'src-msg-42');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.twitch.tv/helix/chat/messages'
        && $request->hasHeader('Authorization', 'Bearer '.REPLY_TOKEN)
        && $request->hasHeader('Client-ID', 'client-id')
        && $request['broadcaster_id'] === '1000'
        && $request['sender_id'] === '1000'
        && $request['reply_parent_message_id'] === 'src-msg-42'
        && str_starts_with($request['message'], 'Want EDOS to build something like this with your team? Tell us about it: '.url('/go/')));

    expect(ChatCommandRun::where('message_id', 'src-msg-42')->value('reply_sent_at'))->not->toBeNull();
});

test('the reply is queued, never posted inside the chat request', function () {
    Queue::fake();

    chatRegistryRun('!orkestera', 'src-1');

    Queue::assertPushed(PostChatReply::class, fn (PostChatReply $job) => $job->provider === IdentityProvider::Twitch
        && $job->channelId === '1000'
        && $job->messageId === 'src-1'
        && str_starts_with($job->reply, 'Orkestera is the agentic workflow suite EDOS builds: '));
});

test('an empty reply posts nothing: a command inside its channel cooldown is silent', function () {
    Queue::fake();

    chatRegistryRun('!orkestera', 'src-1');
    chatRegistryRun('!orkestera', 'src-2');

    Queue::assertPushed(PostChatReply::class, 1);
});

test('"slow down" replies are not posted, so a flood is not answered message for message', function () {
    Queue::fake();
    config(['chat.commands_per_minute' => 1]);

    chatRegistryRun('!edos', 'src-1');
    chatRegistryRun('!orkestera', 'src-2'); // rate-limited per user

    Queue::assertPushed(PostChatReply::class, 1);
});

test('a retried or duplicated job posts the reply once', function () {
    Http::fake(helixSent());
    $job = replyJob();

    $job->handle();
    $job->handle();
    (new PostChatReply(IdentityProvider::Twitch, '1000', 'src-1', 'Hello chat', now()))->handle();

    Http::assertSentCount(1);
});

test('a 5xx or 429 releases the claim and throws, so the retry posts it once', function (int $status) {
    Http::fakeSequence('api.twitch.tv/helix/chat/messages')
        ->push(['message' => 'oops'], $status)
        ->push(['data' => [['message_id' => 'out-1', 'is_sent' => true]]]);
    $job = replyJob();

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(ChatCommandRun::where('message_id', 'src-1')->value('reply_sent_at'))->toBeNull();

    $job->handle(); // the queue's retry
    $job->handle(); // a stray duplicate

    Http::assertSentCount(2);
    expect(ChatCommandRun::where('message_id', 'src-1')->value('reply_sent_at'))->not->toBeNull();
})->with([500, 503, 429]);

test('the per-channel cap holds, per channel, and resets after the window', function () {
    Http::fake(helixSent());
    config(['chat.replies.per_channel' => 2, 'chat.replies.window_seconds' => 30]);
    BroadcasterToken::create([
        'broadcaster_id' => '2000', 'access_token' => 'other', 'refresh_token' => 'r',
        'expires_at' => now()->addHour(), 'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);

    foreach (['a', 'b', 'c', 'd'] as $id) {
        replyJob($id)->handle();
    }
    Http::assertSentCount(2);

    replyJob('other-channel', channelId: '2000')->handle();
    Http::assertSentCount(3);

    $this->travel(31)->seconds();
    replyJob('e')->handle();
    Http::assertSentCount(4);
});

test('replies older than 30 seconds are dropped', function () {
    Http::fake(helixSent());

    replyJob(at: now()->subSeconds(31))->handle();
    replyJob('fresh', at: now()->subSeconds(29))->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['reply_parent_message_id'] === 'fresh');
});

test('a refused reply is logged without the token, and not retried', function () {
    Http::fake(['api.twitch.tv/helix/chat/messages' => Http::response(['error' => 'Unauthorized', 'status' => 401, 'message' => 'Missing scope: user:write:chat'], 401)]);
    Log::spy();

    replyJob()->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        $logged = $message.json_encode($context);

        return $message === 'Twitch refused a chat reply.'
            && $context['status'] === 401
            && $context['message_id'] === 'src-1'
            && ! str_contains($logged, REPLY_TOKEN)
            && ! str_contains($logged, 'refresh-secret');
    });
});

test('a message Twitch drops is logged with its drop reason', function () {
    Http::fake(['api.twitch.tv/helix/chat/messages' => Http::response(['data' => [[
        'message_id' => '', 'is_sent' => false,
        'drop_reason' => ['code' => 'msg_duplicate', 'message' => 'This message is a duplicate.'],
    ]]])]);
    Log::spy();

    replyJob()->handle();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Twitch dropped a chat reply.'
        && $context['drop_code'] === 'msg_duplicate');
});

test('a token without user:write:chat posts nothing and says to reconnect', function () {
    Http::fake(helixSent());
    Log::spy();
    BroadcasterToken::where('broadcaster_id', '1000')->first()->update(['scopes' => ['moderation:read', 'user:read:chat']]);

    replyJob()->handle();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'reconnect'));
});

test('a channel with no broadcaster token posts nothing and does not throw', function () {
    Http::fake(helixSent());
    Log::spy();

    replyJob(channelId: '3000')->handle();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

test('replies longer than Twitch\'s 500 characters are cut', function () {
    Http::fake(helixSent());

    replyJob(reply: str_repeat('é', 600))->handle();

    Http::assertSent(fn (Request $request) => mb_strlen($request['message']) === 500);
});

test('YouTube replies are off by default: nothing is queued', function () {
    Queue::fake();

    chatRegistryRun('!edos', 'yt-1', IdentityProvider::YouTube, 'UCedos');

    expect(PostChatReply::supports(IdentityProvider::YouTube))->toBeFalse();
    Queue::assertNothingPushed();
});

test('with the YouTube flag on, replies are queued, but nothing is sent until YouTube chat lands (#24)', function () {
    config(['chat.replies.youtube' => true]);
    Http::fake();
    Log::spy();

    chatRegistryRun('!edos', 'yt-1', IdentityProvider::YouTube, 'UCedos');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'not implemented'));
});

test('CHAT_REPLIES_ENABLED=false turns replies off everywhere', function () {
    Queue::fake();
    config(['chat.replies.enabled' => false]);

    chatRegistryRun('!edos', 'src-1');

    Queue::assertNothingPushed();
});

test('connecting a channel now asks Twitch for user:write:chat', function () {
    expect(Twitch::BROADCASTER_SCOPES)->toContain('user:write:chat');
});

test('each new source message gets its own reply', function () {
    Http::fake(helixSent());

    chatRegistryRun('!edos', (string) Str::uuid());
    $this->travel(31)->seconds();
    chatRegistryRun('!edos', (string) Str::uuid());

    Http::assertSentCount(2);
});
