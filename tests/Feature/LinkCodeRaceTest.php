<?php

use App\Chat\ChatCommandRegistry;
use App\Chat\ChatCommandResult;
use App\Chat\ChatCommandStatus;
use App\Exceptions\IdentityLinkException;
use App\IdentityProvider;
use App\Models\Identity;
use App\Models\LinkCode;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/** Type $text in YouTube chat as $chatterId, shown as $name. */
function raceChat(string $text, string $chatterId, string $name = 'Jeremy', IdentityProvider $provider = IdentityProvider::YouTube): ?ChatCommandResult
{
    return app(ChatCommandRegistry::class)->run($provider, 'UC-channel', $chatterId, $name, (string) Str::uuid(), $text);
}

// Andras's repro (#102), adopted from AndrasPr88Test PR88-1.

test('a second account typing the same code voids it instead of leaving the first claim for the owner to confirm', function () {
    $owner = User::factory()->create(['name' => 'Jeremy']);
    $code = LinkCode::issueFor($owner);

    raceChat("!link {$code}", 'UC-attacker', 'Jeremy');   // a bot replaying the code it saw in chat wins the race
    raceChat("!link {$code}", 'UC-owner', 'Jeremy');      // the owner's own message, a moment later

    expect($owner->linkCodes()->pending()->get())->toHaveCount(0);
});

test('the order does not matter: the owner first, then the bot, also voids it', function () {
    $owner = User::factory()->create();
    $code = LinkCode::issueFor($owner);

    raceChat("!link {$code}", 'UC-owner');
    raceChat("!link {$code}", 'UC-attacker');

    expect($owner->linkCodes()->pending()->count())->toBe(0)
        ->and($owner->linkCodes()->contested()->count())->toBe(1);
});

test('a second account on another platform also voids it', function () {
    $owner = User::factory()->create();
    $code = LinkCode::issueFor($owner);

    raceChat("!link {$code}", 'UC-owner');
    raceChat("!link {$code}", '999', 'Jeremy', IdentityProvider::Twitch);

    expect($owner->linkCodes()->pending()->count())->toBe(0);
});

test('the same account typing its code twice keeps the pending link', function () {
    $owner = User::factory()->create();
    $code = LinkCode::issueFor($owner);

    raceChat("!link {$code}", 'UC-owner');
    $again = raceChat("!link {$code}", 'UC-owner');

    expect($again->status)->toBe(ChatCommandStatus::Done)
        ->and($again->reply)->toContain('already waiting for confirmation')
        ->and($owner->linkCodes()->pending()->sole()->pending_provider_user_id)->toBe('UC-owner');
});

test('the owner\'s own linked account typing a claimed code still counts as a second account', function () {
    $owner = User::factory()->twitch('42')->create();
    $code = LinkCode::issueFor($owner);

    raceChat("!link {$code}", 'UC-attacker');
    raceChat("!link {$code}", '42', 'Jeremy', IdentityProvider::Twitch);

    expect($owner->linkCodes()->pending()->count())->toBe(0)
        ->and($owner->linkCodes()->contested()->count())->toBe(1);
});

test('a contested code cannot be confirmed from a page opened before the contest', function () {
    $owner = User::factory()->create();
    $code = LinkCode::issueFor($owner);
    raceChat("!link {$code}", 'UC-attacker');
    $pending = $owner->linkCodes()->pending()->sole();

    raceChat("!link {$code}", 'UC-owner');

    expect(fn () => $pending->confirm())->toThrow(IdentityLinkException::class, 'more than one account');

    $this->actingAs($owner);
    Volt::test('settings.linked-accounts')->call('confirmLink', $pending->id)->assertHasErrors('identity');

    expect(Identity::where('provider', 'youtube')->exists())->toBeFalse();
});

test('Settings tells the owner the code was used by more than one account, until they get a new one', function () {
    $owner = User::factory()->create();
    $code = LinkCode::issueFor($owner);
    raceChat("!link {$code}", 'UC-attacker');
    raceChat("!link {$code}", 'UC-owner');

    $this->actingAs($owner)->get('/settings')->assertOk()
        ->assertSee('typed in chat by more than one account')
        ->assertDontSee('Yes, link it');

    $component = Volt::test('settings.linked-accounts')->call('issueLinkCode');
    $component->assertDontSee('typed in chat by more than one account');

    $fresh = $component->get('linkCode');
    expect(raceChat("!link {$fresh}", 'UC-owner')->status)->toBe(ChatCommandStatus::Done)
        ->and($owner->linkCodes()->pending()->sole()->pending_provider_user_id)->toBe('UC-owner');
});

test('the confirmation shows the channel id prominently, and when it was typed', function () {
    $owner = User::factory()->create();
    raceChat('!link '.LinkCode::issueFor($owner), 'UC-0123456789abcdefghijk', 'Jererny');

    $this->actingAs($owner)->get('/settings')->assertOk()
        ->assertSeeInOrder(['Jererny', 'YouTube channel id', 'UC-0123456789abcdefghijk', 'Typed at'])
        ->assertSee('data-pending-id', false)
        ->assertSee('Check that the channel id is yours');
});
