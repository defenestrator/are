<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Identities;
use App\IdentityProvider;
use App\Jobs\EventSub\HandleChatMessage;
use App\Models\Identity;
use App\Models\LinkCode;
use App\Models\Question;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserBan;
use App\Moderation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000', 'services.twitch.broadcaster_ids' => []]);
});

/** Type $text into chat on $provider as the chatter $chatterId. */
function linkChat(string $text, string $chatterId = 'UC-viewer', IdentityProvider $provider = IdentityProvider::YouTube, string $name = 'Tuber'): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run($provider, 'UC-channel', $chatterId, $name, (string) Str::uuid(), $text);
}

function linkModerator(): User
{
    $mod = User::factory()->twitch('77')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);

    return $mod;
}

// Settings

test('Settings offers Link YouTube and shows a one-time code, storing only its HMAC', function () {
    $user = User::factory()->twitch('42')->create();
    $this->actingAs($user);

    $this->get('/settings')->assertOk()->assertSee('Link YouTube');

    $component = Volt::test('settings.linked-accounts')->call('issueLinkCode');
    $code = $component->get('linkCode');

    expect($code)->toMatch('/^['.LinkCode::ALPHABET.']{4}-['.LinkCode::ALPHABET.']{4}$/');
    $component->assertSee('!link '.$code)->assertSee('within 15 minutes');

    $row = LinkCode::sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->code_hash)->toBe(LinkCode::hash($code))
        ->and(json_encode(DB::table('link_codes')->get()))->not->toContain(LinkCode::normalize($code))
        ->and($row->expires_at->diffInMinutes(now()->addMinutes(15)))->toBeLessThan(1);
});

test('the code alphabet has no look-alike characters', function () {
    foreach (str_split('0O1IL5S') as $char) {
        expect(LinkCode::ALPHABET)->not->toContain($char);
    }
});

test('Settings stops offering Link YouTube once a YouTube account is linked', function () {
    $this->actingAs(User::factory()->twitch('42')->youtube('UC-1')->create())
        ->get('/settings')->assertOk()->assertDontSee('Link YouTube');
});

test('a banned user cannot get a link code', function () {
    $user = User::factory()->twitch('42')->create();
    Moderation::ban(linkModerator(), $user, null);

    $this->actingAs($user);
    Volt::test('settings.linked-accounts')->call('issueLinkCode')->assertForbidden();

    expect(LinkCode::count())->toBe(0);
});

// !link

test('!link CODE in YouTube chat links that channel to the user, so it votes as them', function () {
    $user = User::factory()->twitch('42')->create(['name' => 'Kale Fan']);
    $code = LinkCode::issueFor($user);

    $result = linkChat("!link {$code}", 'UC-viewer');

    expect($result->status)->toBe(ChatCommandStatus::Done)
        ->and($result->reply)->toContain('Kale Fan')
        ->and(Identities::findUser(IdentityProvider::YouTube, 'UC-viewer')?->is($user))->toBeTrue()
        ->and(Identity::for(IdentityProvider::YouTube, 'UC-viewer')->sole()->name)->toBe('Tuber')
        ->and(User::count())->toBe(1);

    // One person, one vote: !vote from YouTube and from Twitch count once.
    $question = Question::factory()->for(User::factory()->facebook())->create();
    linkChat("!vote {$question->id}", 'UC-viewer');
    linkChat("!vote {$question->id}", '42', IdentityProvider::Twitch);
    expect($question->voteCount())->toBe(1);
});

test('codes are case- and dash-insensitive', function () {
    $user = User::factory()->twitch('42')->create();
    $code = LinkCode::issueFor($user);

    expect(linkChat('!link '.strtolower(str_replace('-', ' ', $code)))->status)->toBe(ChatCommandStatus::Done);
});

test('a code works once', function () {
    $user = User::factory()->twitch('42')->create();
    $code = LinkCode::issueFor($user);

    expect(linkChat("!link {$code}", 'UC-first')->status)->toBe(ChatCommandStatus::Done)
        ->and(linkChat("!link {$code}", 'UC-second')->status)->toBe(ChatCommandStatus::Rejected)
        ->and(Identity::for(IdentityProvider::YouTube, 'UC-second')->exists())->toBeFalse();
});

test('a code expires after 15 minutes', function () {
    $code = LinkCode::issueFor(User::factory()->twitch('42')->create());

    $this->travel(16)->minutes();

    expect(linkChat("!link {$code}")->reply)->toContain('wrong or has expired')
        ->and(Identity::where('provider', 'youtube')->exists())->toBeFalse();
});

test('a new code replaces the unused one', function () {
    $user = User::factory()->twitch('42')->create();
    $old = LinkCode::issueFor($user);
    $new = LinkCode::issueFor($user);

    expect(linkChat("!link {$old}")->status)->toBe(ChatCommandStatus::Rejected)
        ->and(linkChat("!link {$new}")->status)->toBe(ChatCommandStatus::Done);
});

test('a wrong or missing code is rejected with usage help', function () {
    expect(linkChat('!link')->reply)->toContain('Usage: !link CODE')
        ->and(linkChat('!link ABCD-EFGH')->reply)->toContain('wrong or has expired')
        ->and(linkChat('!link not-a-code-at-all')->status)->toBe(ChatCommandStatus::Rejected);
});

test('guessing codes runs into the per-person rate limit', function () {
    config(['chat.commands_per_minute' => 3]);
    LinkCode::issueFor(User::factory()->twitch('42')->create());

    $statuses = collect(range(1, 5))->map(fn () => linkChat('!link ABCD-EFGH', 'UC-guesser')->status);

    expect($statuses->filter(fn ($s) => $s === ChatCommandStatus::RateLimited)->count())->toBe(2);
});

test('a channel another user owns is not linked or merged, and the code stays usable', function () {
    $owner = User::factory()->youtube('UC-taken')->create();
    $user = User::factory()->twitch('42')->create();
    $code = LinkCode::issueFor($user);

    $result = linkChat("!link {$code}", 'UC-taken');

    expect($result->status)->toBe(ChatCommandStatus::Rejected)
        ->and($result->reply)->toContain('already linked to a different ARE account')
        ->and(Identity::for(IdentityProvider::YouTube, 'UC-taken')->sole()->user_id)->toBe($owner->id)
        ->and(LinkCode::findUsable($code))->not->toBeNull();
});

test('a user who already has a YouTube account cannot link a second one', function () {
    $user = User::factory()->twitch('42')->youtube('UC-mine')->create();
    $code = LinkCode::issueFor($user);

    expect(linkChat("!link {$code}", 'UC-other')->reply)->toContain('already have a YouTube account linked');
});

test('typing your own code from an account already linked to you just says so', function () {
    $user = User::factory()->twitch('42')->youtube('UC-mine')->create();
    $code = LinkCode::issueFor($user);

    expect(linkChat("!link {$code}", 'UC-mine')->reply)->toContain('already linked to you')
        ->and(LinkCode::findUsable($code))->toBeNull();
});

// Bans (#76)

test('a code from a user banned after issuing it cannot link', function () {
    $user = User::factory()->twitch('42')->create();
    $code = LinkCode::issueFor($user);
    Moderation::ban(linkModerator(), $user, null);

    expect(linkChat("!link {$code}")->reply)->toContain('cannot link accounts while you are banned')
        ->and(Identity::where('provider', 'youtube')->exists())->toBeFalse();
});

test('a banned YouTube channel cannot be linked to a clean user', function () {
    $troll = User::factory()->youtube('UC-troll')->create();
    Moderation::ban(linkModerator(), $troll, null);
    $troll->delete();

    $clean = User::factory()->twitch('42')->create();
    $code = LinkCode::issueFor($clean);

    expect(linkChat("!link {$code}", 'UC-troll')->reply)->toContain('banned here, so it cannot be linked')
        ->and(Identity::for(IdentityProvider::YouTube, 'UC-troll')->exists())->toBeFalse()
        ->and($clean->isBanned())->toBeFalse()
        ->and(UserBan::inEffect()->forAccount(IdentityProvider::YouTube, 'UC-troll')->exists())->toBeTrue();
});

test('a linked chatter who is banned is stopped by the registry before !link runs', function () {
    $banned = User::factory()->youtube('UC-banned')->create();
    Moderation::ban(linkModerator(), $banned, null);
    $code = LinkCode::issueFor(User::factory()->twitch('42')->create());

    expect(linkChat("!link {$code}", 'UC-banned')->status)->toBe(ChatCommandStatus::Banned)
        ->and(LinkCode::findUsable($code))->not->toBeNull();
});

// Twitch chat

test('Twitch chat can link a Twitch account the same way', function () {
    $user = User::factory()->facebook('fb-1')->create();
    $code = LinkCode::issueFor($user);

    (new HandleChatMessage((string) Str::uuid(), now()->toIso8601ZuluString(), [
        'broadcaster_user_id' => '1000',
        'broadcaster_user_login' => 'edos',
        'broadcaster_user_name' => 'EDOS',
        'chatter_user_id' => '4145994',
        'chatter_user_login' => 'viewer32',
        'chatter_user_name' => 'viewer32',
        'message_id' => (string) Str::uuid(),
        'message' => ['text' => "!link {$code}", 'fragments' => []],
        'message_type' => 'text',
        'badges' => [],
    ]))->handle();

    expect($user->fresh()->twitch_id)->toBe('4145994');
});

// Housekeeping

test('expired codes are pruned after a day', function () {
    $user = User::factory()->twitch('42')->create();
    LinkCode::issueFor($user);
    $this->travel(2)->days();
    LinkCode::issueFor(User::factory()->twitch('43')->create());

    $this->artisan('model:prune', ['--model' => [LinkCode::class]])->assertSuccessful();

    expect(LinkCode::count())->toBe(1);
});

test('consuming a code succeeds once', function () {
    LinkCode::issueFor(User::factory()->twitch('42')->create());
    $code = LinkCode::sole();

    expect($code->consume())->toBeTrue()
        ->and($code->consume())->toBeFalse();
});
