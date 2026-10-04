<?php

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\IdentityProvider;
use App\Jobs\PostChatReply;
use App\Models\BroadcasterToken;
use App\Models\LinkCode;
use App\Models\Question;
use App\Models\Track;
use App\Models\User;
use App\Twitch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * #128: chat replies post as the broadcaster, so no reply may contain text the
 * chatter chose. These run every registered command with a hostile display
 * name and hostile input, on every platform, linked and unlinked, and check
 * every reply, both the command's result and what would be posted.
 */

const HOSTILE_NAME = 'FREE VBUCKS at scam.example';
const HOSTILE_INPUT = 'Visit scam.example for FREE VBUCKS';

/** Markers that must never reach a reply, compared case-insensitively. */
const HOSTILE_MARKERS = ['scam.example', 'vbucks'];

function hostileRun(IdentityProvider $provider, string $chatterId, string $text): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run($provider, $provider === IdentityProvider::Twitch ? '1000' : 'UCedos', $chatterId, HOSTILE_NAME, (string) Str::uuid(), $text);
}

function expectNoHostileText(string $reply, string $context): void
{
    foreach (HOSTILE_MARKERS as $marker) {
        expect(str_contains(mb_strtolower($reply), $marker))->toBeFalse("Reply to {$context} repeats chatter text: {$reply}");
    }
}

test('no registered command repeats a hostile display name or hostile input in its reply', function (IdentityProvider $provider, bool $linked) {
    Bus::fake([PostChatReply::class]);
    config(['chat.replies.youtube' => true, 'chat.commands_per_minute' => 1000, 'are.chat_links.cooldown_seconds' => 0]);

    $chatterId = $provider === IdentityProvider::Twitch ? '6660001' : 'UC-hostile-chatter';
    $viewer = $linked ? User::factory()->withIdentity($provider, $chatterId)->create() : null;
    $question = Question::factory()->create();
    $track = Track::factory()->streamSafe()->create();
    // A track whose title contains the hostile text, which an operator would
    // never upload, still must not reach a reply (titles are not echoed).
    Track::factory()->streamSafe()->create(['title' => HOSTILE_INPUT]);
    $names = app(ChatCommandRegistry::class)->names();
    expect($names)->toContain('q', 'vote', 'link', 'song', 'edos', 'orkestera');

    $messages = [];
    foreach ($names as $name) {
        // Every command, given hostile input as its whole argument.
        $messages[] = "!{$name} ".HOSTILE_INPUT;
        $messages[] = "!{$name}";
    }
    // And the success paths, which a viewer can reach with a hostile name.
    $messages[] = '!q '.HOSTILE_INPUT;
    $messages[] = "!vote {$question->id}";
    $messages[] = "!vote {$question->id} ".HOSTILE_INPUT;
    $messages[] = "!song {$track->id}";
    $messages[] = '!song scam';
    $messages[] = '!link '.LinkCode::issueFor(User::factory()->create());
    if ($viewer !== null) {
        $messages[] = '!link '.LinkCode::issueFor($viewer);
    }

    $replies = 0;
    foreach ($messages as $text) {
        $result = hostileRun($provider, $chatterId, $text);
        if ($result !== null) {
            expectNoHostileText($result->reply, $text);
            $replies++;
        }
    }

    Bus::assertDispatched(PostChatReply::class);
    foreach (Bus::dispatched(PostChatReply::class) as $job) {
        expectNoHostileText($job->reply, 'a queued reply');
    }
    expect($replies)->toBeGreaterThan(count($names));
})->with([
    'Twitch, linked' => [IdentityProvider::Twitch, true],
    'Twitch, unlinked' => [IdentityProvider::Twitch, false],
    'YouTube, linked' => [IdentityProvider::YouTube, true],
    'YouTube, unlinked' => [IdentityProvider::YouTube, false],
]);

// Andras's reproduction from the review of #126.
test('a chatter-chosen display name is not echoed into the reply ARE posts as the channel owner', function () {
    Bus::fake([PostChatReply::class]);
    config(['chat.replies.youtube' => true]);
    $code = LinkCode::issueFor(User::factory()->create());

    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, 'UC-channel', 'UC-attacker', HOSTILE_NAME, (string) Str::uuid(), "!link {$code}");

    Bus::assertDispatched(PostChatReply::class, fn (PostChatReply $job) => ! str_contains($job->reply, 'scam.example')
        && str_starts_with($job->reply, 'Almost done: confirm this YouTube account'));
});

// --- The central backstop in PostChatReply ------------------------------------------

test('PostChatReply refuses a reply that repeats the chatter\'s name or input, whatever command made it', function () {
    Http::fake();
    Log::spy();
    $rogue = new class implements ChatCommand
    {
        public function names(): array
        {
            return ['rogue'];
        }

        public function requiresUser(): bool
        {
            return false;
        }

        public function handle(ChatCommandInvocation $invocation): ChatCommandResult
        {
            return ChatCommandResult::done("Thanks, {$invocation->chatterName}!");
        }
    };
    app()->instance(ChatCommandRegistry::class, new ChatCommandRegistry([$rogue]));

    hostileRun(IdentityProvider::Twitch, '6660001', '!rogue');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'repeats the chatter')
        && ! str_contains(json_encode($context), 'scam.example'));
});

test('the backstop matches names and input on whole words, ignoring case, spacing and format characters', function (string $reply, string $name, string $input, bool $echoes) {
    expect(PostChatReply::echoesChatter($reply, $name, $input))->toBe($echoes);
})->with([
    'the name' => ['Hi FREE VBUCKS at scam.example!', HOSTILE_NAME, '', true],
    'the name, other case and spacing' => ['hi free  vbucks AT scam.example', HOSTILE_NAME, '', true],
    'the name, split by a zero-width space' => ["hi FREE VBUCKS at scam\u{200B}.example", HOSTILE_NAME, '', true],
    'the input' => ['You said: visit scam.example for free vbucks', '', HOSTILE_INPUT, true],
    'a fixed template' => ['Question #12 is in the queue. Vote with !vote 12', 'Kale Fan', 'sing about kale', false],
    'a short name inside a word' => ['Almost done: confirm this account', 'Al', '', false],
    'a name inside a longer word' => ['Downvoted question #4.', 'Down', '', false],
    'numeric input' => ['Upvoted question #1234.', '', '1234', false],
    'one ordinary word of input' => ['Question #5 is in the queue.', '', 'queue', false],
]);

test('a normal reply is still posted', function () {
    Http::fake(['api.twitch.tv/helix/chat/messages' => Http::response(['data' => [['message_id' => 'out-1', 'is_sent' => true]]])]);
    BroadcasterToken::create([
        'broadcaster_id' => '1000', 'access_token' => 'tok', 'refresh_token' => 'ref',
        'expires_at' => now()->addHour(), 'scopes' => Twitch::BROADCASTER_SCOPES,
    ]);
    User::factory()->twitch('6660001')->create();

    hostileRun(IdentityProvider::Twitch, '6660001', '!q '.HOSTILE_INPUT);

    Http::assertSent(fn ($request) => str_starts_with($request['message'], 'Question #'));
});
