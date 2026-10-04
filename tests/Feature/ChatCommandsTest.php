<?php

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Events\QuestionSubmitted;
use App\Events\VoteCast;
use App\IdentityProvider;
use App\Jobs\EventSub\HandleChatMessage;
use App\Models\ChatCommandRun;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0])->call('upvote');
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

test('the app registers !q, !vote, !orkestera, !edos, !link and !song', function () {
    expect(app(ChatCommandRegistry::class)->names())->toBe(['q', 'vote', 'orkestera', 'edos', 'link', 'song']);
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

    Volt::test('question-card', ['question' => $question, 'voteCount' => 0])
        ->assertSee('#'.$question->id);
});

// --- At most once per message (Andras, review of #77) --------------------------

function andrasChatRun(User $user, string $text, string $messageId = 'm1')
{
    return app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', $user->twitch_id, 'Someone', $messageId, $text);
}

test('PR77-1: chat !q applies the same subscriber cap as the web', function () {
    $user = User::factory()->create();
    UserTwitchSubscription::create(['user_id' => $user->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier1]);
    Topic::set('Kale');
    Question::factory()->count(6)->for($user)->create();

    expect(andrasChatRun($user, '!q one more please')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(Question::count())->toBe(6);
});

test('PR77-2: a redelivered chat message (same message id) does not add the question twice', function () {
    $user = User::factory()->create();

    andrasChatRun($user, '!q sing about soup', 'msg-42');
    andrasChatRun($user, '!q sing about soup', 'msg-42');   // what a job retry after the command ran would do

    expect(Question::where('question', 'sing about soup')->count())->toBe(1);
});

test('PR77-3: chat text with bidi overrides reaches the overlay as-is', function () {
    $user = User::factory()->create();
    andrasChatRun($user, "!q \u{202E}olleh dlrow");

    expect(Question::first()?->question)->not->toContain("\u{202E}");
});

test('a retried chat job does not run the command again', function () {
    chatViewer();
    $event = [
        'broadcaster_user_id' => '1000', 'chatter_user_id' => '4145994', 'chatter_user_login' => 'viewer32', 'chatter_user_name' => 'viewer32',
        'message_id' => 'chat-msg-1', 'message' => ['text' => '!q only once please'], 'message_type' => 'text', 'badges' => [],
    ];
    $job = new HandleChatMessage('eventsub-msg-1', now()->toIso8601ZuluString(), $event);

    $job->handle();
    // The job's own handled marker was lost (a cache blip, or a worker killed after the command ran).
    Cache::forget('twitch.eventsub.handled.eventsub-msg-1');
    $job->handle();

    expect(Question::count())->toBe(1)
        ->and(ChatCommandRun::sole())
        ->message_id->toBe('chat-msg-1')
        ->command->toBe('q')
        ->status->toBe('done');
});

test('the same message id on another platform is a different message', function () {
    User::factory()->twitch('4145994')->youtube('UC-viewer')->create();

    runChatCommandWithId('!q from twitch', 'shared-id');
    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, 'UC-chan', 'UC-viewer', 'v', 'shared-id', '!q from youtube');

    expect(Question::count())->toBe(2);
});

test('a failure before the command runs releases the claim, so the retry runs it', function () {
    chatViewer();
    $limiter = RateLimiter::getFacadeRoot();
    RateLimiter::shouldReceive('tooManyAttempts')->once()->andThrow(new RuntimeException('cache down'));

    expect(fn () => runChatCommandWithId('!q after the blip', 'retry-me'))->toThrow(RuntimeException::class, 'cache down');
    expect(ChatCommandRun::count())->toBe(0);

    RateLimiter::swap($limiter);
    expect(runChatCommandWithId('!q after the blip', 'retry-me')->status)->toBe(ChatCommandStatus::Done)
        ->and(Question::count())->toBe(1);
});

test('old command claims are pruned', function () {
    ChatCommandRun::claim(IdentityProvider::Twitch, 'old', 'q');
    $this->travel(8)->days();
    ChatCommandRun::claim(IdentityProvider::Twitch, 'new', 'q');

    $this->artisan('model:prune', ['--model' => [ChatCommandRun::class]])->assertSuccessful();

    expect(ChatCommandRun::pluck('message_id')->all())->toBe(['new']);
});

function runChatCommandWithId(string $text, string $messageId): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run(IdentityProvider::Twitch, '1000', '4145994', 'viewer32', $messageId, $text);
}

// --- Bidi controls are stripped at submit, for web and chat ---------------------

test('bidi control characters are stripped from questions, from chat and the web', function () {
    $viewer = chatViewer();

    runChatCommand("!q \u{202E}olleh\u{2066} dlrow\u{2069}\u{200F}");
    $this->actingAs($viewer);
    Volt::test('vote')->set('question', "\u{202D}web \u{200E}question\u{202C}")->call('saveQuestion')->assertHasNoErrors();

    expect(Question::orderBy('id')->pluck('question')->all())->toBe(['olleh dlrow', 'web question']);
});

test('a question that is only bidi controls is rejected', function () {
    chatViewer();

    expect(runChatCommand("!q \u{202E}\u{202E}\u{202E}\u{202E}")->status)->toBe(ChatCommandStatus::Rejected)
        ->and(Question::count())->toBe(0);
});

test('emoji joined with zero-width joiners are kept', function () {
    chatViewer();

    runChatCommand("!q songs for the \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}");

    expect(Question::sole()->question)->toBe("songs for the \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}");
});

// Chat commands (#77) write through App\QuestionQueue, as the vote page does,
// so a chat vote or !q shows live for everyone with the page open.
test('a !vote from chat broadcasts VoteCast with the new total and version', function () {
    Event::fake([VoteCast::class]);
    $question = Question::factory()->create();
    $question->recordVote(User::factory()->create(), 1);
    User::factory()->twitch('4145994')->create();

    twitchChat("!vote {$question->id}");

    expect($question->fresh()->vote_version)->toBe(2);
    Event::assertDispatched(VoteCast::class, fn (VoteCast $e) => $e->questionId === $question->id
        && $e->broadcastWith() === ['question_id' => $question->id, 'votes' => 2, 'version' => 2]);
});

test('a !q from chat broadcasts QuestionSubmitted', function () {
    Event::fake([QuestionSubmitted::class]);
    User::factory()->twitch('4145994')->create();

    twitchChat('!q Sing about kale');

    $question = Question::sole();
    Event::assertDispatched(QuestionSubmitted::class, fn (QuestionSubmitted $e) => $e->questionId === $question->id);
});

test('a refused chat vote broadcasts nothing', function () {
    Event::fake([VoteCast::class]);
    $question = Question::factory()->create(['archived_at' => now()]);
    User::factory()->twitch('4145994')->create();

    twitchChat("!vote {$question->id}");

    Event::assertNotDispatched(VoteCast::class);
});
