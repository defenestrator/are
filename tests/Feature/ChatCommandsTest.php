<?php

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\IdentityProvider;
use App\Jobs\EventSub\HandleChatMessage;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/**
 * Run a Twitch chat message through the real path: the queued chat job, the
 * ChatMessageReceived event, the Twitch listener and the registry.
 */
function twitchChat(string $text, string $chatterId = '4145994', array $overrides = []): void
{
    $event = $overrides + [
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'chatter_user_id' => $chatterId,
        'chatter_user_login' => 'viewer32',
        'chatter_user_name' => 'viewer32',
        'message_id' => (string) Str::uuid(),
        'message' => ['text' => $text, 'fragments' => []],
        'message_type' => 'text',
        'badges' => [],
    ];

    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), $event))->handle();
}

/** Run a command through the registry directly, to read its result. */
function runChatCommand(string $text, string $chatterId = '4145994', IdentityProvider $provider = IdentityProvider::Twitch): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run($provider, '1000', $chatterId, 'viewer32', (string) Str::uuid(), $text);
}

function chatViewer(string $twitchId = '4145994'): User
{
    return User::factory()->twitch($twitchId)->create();
}

// --- !q ----------------------------------------------------------------------

test('!q from a linked Twitch chatter adds their question to the queue', function () {
    $viewer = chatViewer();

    twitchChat('!q   Sing about   kale  ');

    $question = Question::sole();
    expect($question->user_id)->toBe($viewer->id)
        ->and($question->question)->toBe('Sing about   kale')
        ->and($question->archived_at)->toBeNull();
});

test('!q answers with the new question number', function () {
    chatViewer();

    $result = runChatCommand('!Q songs about kale');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toContain('#'.Question::sole()->id);
});

test('!q from a chatter with no linked user is ignored, and creates no user', function () {
    $users = User::count();

    $result = runChatCommand('!q who am I', chatterId: '999999');

    expect($result->status)->toBe(ChatCommandStatus::Unlinked)
        ->and(Question::count())->toBe(0)
        ->and(User::count())->toBe($users);
});

test('!q is refused for a chatter banned on Twitch', function () {
    chatViewer();
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '4145994']);

    expect(runChatCommand('!q let me back in')->status)->toBe(ChatCommandStatus::Banned)
        ->and(Question::count())->toBe(0);
});

test('!q is refused for a user banned here, whichever platform they chat from', function () {
    $viewer = User::factory()->twitch('4145994')->youtube('UC-viewer')->create();
    $viewer->localBans()->create(['reason' => 'spam']);

    expect(runChatCommand('!q from twitch')->status)->toBe(ChatCommandStatus::Banned)
        ->and(runChatCommand('!q from youtube', 'UC-viewer', IdentityProvider::YouTube)->status)->toBe(ChatCommandStatus::Banned)
        ->and(Question::count())->toBe(0);
});

test('!q respects the subscriber question cap, as the vote page does', function () {
    $viewer = chatViewer();
    UserTwitchSubscription::create(['user_id' => $viewer->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier1]);
    Topic::set('Kale');
    Question::factory()->count(TwitchSubscription::Tier1->maxActiveQuestions())->for($viewer)->create();

    $result = runChatCommand('!q one too many');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toBe('You have reached the suggestion limit')
        ->and(Question::count())->toBe(TwitchSubscription::Tier1->maxActiveQuestions());
});

test('!q validates the question like the vote page', function (string $text) {
    chatViewer();

    expect(runChatCommand($text)->status)->toBe(ChatCommandStatus::Rejected)
        ->and(Question::count())->toBe(0);
})->with([
    'missing' => '!q',
    'too short' => '!q hi',
    'too long' => '!q '.str_repeat('a', 421),
]);

// --- !vote -------------------------------------------------------------------

test('!vote upvotes a question as the linked user, and down replaces it', function () {
    $viewer = chatViewer();
    $question = Question::factory()->create();

    twitchChat("!vote {$question->id}");
    expect($question->voteCount())->toBe(1);

    twitchChat("!vote #{$question->id} down");
    expect($question->voteCount())->toBe(-1)
        ->and(DB::table('question_votes')->where('user_id', $viewer->id)->count())->toBe(1);
});

test('voting from chat and from the vote page is one vote per person', function () {
    $viewer = chatViewer();
    $question = Question::factory()->create();

    $this->actingAs($viewer);
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])->call('upvote', $question->id);
    twitchChat("!vote {$question->id} up");

    expect($question->voteCount())->toBe(1);
});

test('!vote refuses archived and unknown questions, and bad syntax', function () {
    chatViewer();
    $archived = Question::factory()->create(['archived_at' => now()]);

    expect(runChatCommand("!vote {$archived->id}")->status)->toBe(ChatCommandStatus::Rejected)
        ->and(runChatCommand('!vote 999999')->reply)->toBe('There is no question #999999.')
        ->and(runChatCommand('!vote')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(runChatCommand('!vote kale')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(runChatCommand("!vote {$archived->id} sideways")->status)->toBe(ChatCommandStatus::Rejected)
        ->and(DB::table('question_votes')->count())->toBe(0);
});

test('!vote is refused for a banned user', function () {
    chatViewer();
    TwitchBan::create(['broadcaster_id' => '2000', 'twitch_user_id' => '4145994', 'ends_at' => now()->addMinutes(5)]);
    $question = Question::factory()->create();

    twitchChat("!vote {$question->id}");

    expect(DB::table('question_votes')->count())->toBe(0);
});

// --- The registry ------------------------------------------------------------

test('ordinary messages and unknown commands are not commands', function (string $text) {
    chatViewer();

    expect(runChatCommand($text))->toBeNull();
})->with(['hello chat', 'what does !q do', '!', '!nope q', '']);

test('commands are rate-limited per person, across platforms', function () {
    config(['chat.commands_per_minute' => 2]);
    User::factory()->twitch('4145994')->youtube('UC-viewer')->create();
    $question = Question::factory()->create();

    expect(runChatCommand("!vote {$question->id}")->status)->toBe(ChatCommandStatus::Done)
        ->and(runChatCommand("!vote {$question->id}", 'UC-viewer', IdentityProvider::YouTube)->status)->toBe(ChatCommandStatus::Done)
        ->and(runChatCommand('!q one more thing')->status)->toBe(ChatCommandStatus::RateLimited)
        ->and(Question::count())->toBe(1);

    // Another person is unaffected.
    chatViewer('5555');
    expect(runChatCommand("!vote {$question->id}", '5555')->status)->toBe(ChatCommandStatus::Done);

    $this->travel(61)->seconds();
    expect(runChatCommand('!q one more thing')->status)->toBe(ChatCommandStatus::Done);
});

test('a YouTube chatter resolves to their user like a Twitch one', function () {
    $viewer = User::factory()->youtube('UC-viewer')->create();

    runChatCommand('!q from youtube', 'UC-viewer', IdentityProvider::YouTube);

    expect(Question::sole()->user_id)->toBe($viewer->id);
});

test('a command that does not require a user runs for an unlinked chatter', function () {
    $command = new class implements ChatCommand
    {
        public ?ChatCommandInvocation $seen = null;

        public function names(): array
        {
            return ['link'];
        }

        public function requiresUser(): bool
        {
            return false;
        }

        public function handle(ChatCommandInvocation $invocation): ChatCommandResult
        {
            $this->seen = $invocation;

            return ChatCommandResult::done();
        }
    };
    $registry = new ChatCommandRegistry([$command]);

    $result = $registry->run(IdentityProvider::Twitch, '1000', '999999', 'stranger', 'msg-1', '!LINK  ABC123 ');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($command->seen->name)->toBe('link')
        ->and($command->seen->arguments)->toBe('ABC123')
        ->and($command->seen->chatterId)->toBe('999999')
        ->and($command->seen->channelId)->toBe('1000')
        ->and($command->seen->messageId)->toBe('msg-1')
        ->and($command->seen->user)->toBeNull();
});

test('a command that throws is reported and does not fail the chat job', function () {
    $command = new class implements ChatCommand
    {
        public function names(): array
        {
            return ['boom'];
        }

        public function requiresUser(): bool
        {
            return false;
        }

        public function handle(ChatCommandInvocation $invocation): ChatCommandResult
        {
            throw new RuntimeException('kaboom');
        }
    };
    app()->instance(ChatCommandRegistry::class, new ChatCommandRegistry([$command]));
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
        $reported[] = $e->getMessage();
    });

    twitchChat('!boom');

    expect($reported)->toBe(['kaboom']);
});

test('two commands cannot claim the same name', function () {
    $make = fn () => new class implements ChatCommand
    {
        public function names(): array
        {
            return ['Q'];
        }

        public function requiresUser(): bool
        {
            return true;
        }

        public function handle(ChatCommandInvocation $invocation): ChatCommandResult
        {
            return ChatCommandResult::done();
        }
    };

    expect(fn () => new ChatCommandRegistry([$make(), $make()]))->toThrow(LogicException::class);
});

test('the app registers !q and !vote', function () {
    expect(app(ChatCommandRegistry::class)->names())->toBe(['q', 'vote']);
});

test('parse splits the name from its arguments', function (string $text, ?array $expected) {
    expect(ChatCommandRegistry::parse($text))->toBe($expected);
})->with([
    ['!q hello world', ['q', 'hello world']],
    ['  !VOTE   12   down ', ['vote', '12   down']],
    ['!q', ['q', '']],
    ['q hello', null],
    ['! q', null],
]);

// --- Twitch specifics ----------------------------------------------------------

test('a Shared Chat message from another channel we serve runs only on its own channel', function () {
    chatViewer();

    twitchChat('!q relayed copy', overrides: ['source_broadcaster_user_id' => '2000']);
    expect(Question::count())->toBe(0);

    twitchChat('!q from a partner channel', overrides: ['source_broadcaster_user_id' => '7777']);
    expect(Question::count())->toBe(1);
});

// --- Vote numbers are visible ----------------------------------------------------

test('the vote page shows each question number for !vote', function () {
    $this->actingAs(chatViewer());
    $question = Question::factory()->create();

    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->assertSee('#'.$question->id);
});
