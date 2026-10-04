<?php

use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Chat\ModeratorChatCommand;
use App\IdentityProvider;
use App\Jobs\EventSub\HandleChatMessage;
use App\Models\Question;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Moderation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// TestCase serves two channels: 1000 (primary) and 2000. Call them A and B.

/** A moderator of exactly one served channel. */
function modOf(string $broadcasterId, ?string $twitchId = null): User
{
    $user = User::factory()->twitch($twitchId)->create();
    TwitchModerator::create(['broadcaster_id' => $broadcasterId, 'twitch_user_id' => $user->twitch_id]);

    return $user;
}

/**
 * Registers a stand-in moderator-only command, !modtest, the way !clip
 * (#11) declares itself, and returns the list of channels it actually ran on.
 */
function registerModeratorCommand(): ArrayObject
{
    $ran = new ArrayObject;

    app(ChatCommandRegistry::class)->register(new class($ran) implements ModeratorChatCommand
    {
        public function __construct(private ArrayObject $ran) {}

        public function names(): array
        {
            return ['modtest'];
        }

        public function requiresUser(): bool
        {
            return true;
        }

        public function handle(ChatCommandInvocation $invocation): ChatCommandResult
        {
            $this->ran[] = $invocation->channelId;

            return ChatCommandResult::done();
        }
    });

    return $ran;
}

function modCommandOn(string $channelId, User $chatter, IdentityProvider $provider = IdentityProvider::Twitch): ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run($provider, $channelId, $chatter->twitch_id ?? 'x', 'someone', (string) Str::uuid(), '!modtest');
}

test('isModeratorOf and isBroadcasterOf are scoped to one served channel', function () {
    $modOfB = modOf('2000');
    $broadcasterOfA = User::factory()->twitch('1000')->create();
    TwitchModerator::create(['broadcaster_id' => '9999', 'twitch_user_id' => $modOfB->twitch_id]);

    expect($modOfB->isModerator())->toBeTrue()          // any served channel
        ->and($modOfB->isModeratorOf('2000'))->toBeTrue()
        ->and($modOfB->isModeratorOf('1000'))->toBeFalse()
        ->and($modOfB->isModeratorOf('9999'))->toBeFalse()   // a channel this app does not serve
        ->and($broadcasterOfA->isBroadcasterOf('1000'))->toBeTrue()
        ->and($broadcasterOfA->isBroadcasterOf('2000'))->toBeFalse();
});

test('the moderateChannel gate: a mod of B is refused on A and allowed on B; the broadcaster of A is allowed on A only', function () {
    $modOfB = modOf('2000');
    $broadcasterOfA = User::factory()->twitch('1000')->create();

    expect($modOfB->can('moderateChannel', '1000'))->toBeFalse()
        ->and($modOfB->can('moderateChannel', '2000'))->toBeTrue()
        ->and($broadcasterOfA->can('moderateChannel', '1000'))->toBeTrue()
        ->and($broadcasterOfA->can('moderateChannel', '2000'))->toBeFalse()
        ->and(User::factory()->create()->can('moderateChannel', '1000'))->toBeFalse();
});

test('a banned moderator fails the channel gate', function () {
    $modOfA = modOf('1000');
    TwitchBan::create(['broadcaster_id' => '2000', 'twitch_user_id' => $modOfA->twitch_id]);

    expect($modOfA->can('moderateChannel', '1000'))->toBeFalse();
});

test('a moderator-only chat command: a mod of B is refused on A and allowed on B', function () {
    $ran = registerModeratorCommand();
    $modOfB = modOf('2000');

    $onA = modCommandOn('1000', $modOfB);
    $onB = modCommandOn('2000', $modOfB);

    expect($onA->status)->toBe(ChatCommandStatus::Rejected)
        ->and($onA->reply)->toBe('Only moderators of this channel can use !modtest.')
        ->and($onB->status)->toBe(ChatCommandStatus::Done)
        ->and($ran->getArrayCopy())->toBe(['2000']);
});

test('a moderator-only chat command: the broadcaster of A is allowed on A, and a viewer is refused', function () {
    $ran = registerModeratorCommand();

    expect(modCommandOn('1000', User::factory()->twitch('1000')->create())->status)->toBe(ChatCommandStatus::Done)
        ->and(modCommandOn('1000', User::factory()->twitch()->create())->status)->toBe(ChatCommandStatus::Rejected)
        ->and($ran->getArrayCopy())->toBe(['1000']);
});

test('a moderator-only chat command is refused on a platform with no known channel moderators', function () {
    $ran = registerModeratorCommand();
    $mod = modOf('1000');
    $mod->identities()->create(['provider' => IdentityProvider::YouTube, 'provider_user_id' => 'yt-mod']);

    $result = app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, '1000', 'yt-mod', 'mod', (string) Str::uuid(), '!modtest');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)->and($ran->count())->toBe(0);
});

test('Twitch chat checks the channel the message arrived on', function () {
    $ran = registerModeratorCommand();
    $modOfB = modOf('2000', '5550001');

    foreach (['1000', '2000'] as $channel) {
        (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), [
            'broadcaster_user_id' => $channel,
            'broadcaster_user_login' => 'chan'.$channel,
            'broadcaster_user_name' => 'Chan'.$channel,
            'chatter_user_id' => $modOfB->twitch_id,
            'chatter_user_login' => 'modb',
            'chatter_user_name' => 'ModB',
            'message_id' => (string) Str::uuid(),
            'message' => ['text' => '!modtest', 'fragments' => []],
            'message_type' => 'text',
            'badges' => [],
        ]))->handle();
    }

    expect($ran->getArrayCopy())->toBe(['2000']);
});

test('channel moderator checks are memoised per user and per channel', function () {
    $modOfB = modOf('2000');

    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach (range(1, 3) as $_) {
        $modOfB->isModeratorOf('1000');
        $modOfB->isModeratorOf('2000');
    }
    DB::disableQueryLog();

    $queries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'twitch_moderators'));

    // One query per channel, not one per call, and never one channel's answer for another.
    expect($queries)->toHaveCount(2)
        ->and($modOfB->isModeratorOf('1000'))->toBeFalse()
        ->and($modOfB->isModeratorOf('2000'))->toBeTrue();
});

test('the web queue is shared by every served channel, so a mod of B moderates it', function () {
    $modOfB = modOf('2000');
    $question = Question::factory()->create();

    expect($modOfB->can('moderate'))->toBeTrue()
        ->and($modOfB->can('delete', $question))->toBeTrue();

    Moderation::deleteQuestion($modOfB, $question);
    expect(Question::count())->toBe(0);
});
