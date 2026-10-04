<?php

use App\Chat\ChatCommandRegistry;
use App\IdentityProvider;
use App\Models\Identity;
use App\Models\LinkCode;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/** A link typed in YouTube chat and waiting for $owner; returns the pending code's id. */
function pendingLinkFor(User $owner, string $channel = 'UC-tab'): int
{
    $code = LinkCode::issueFor($owner);
    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, 'UC-channel', $channel, 'Tuber', (string) Str::uuid(), "!link {$code}");

    return $owner->linkCodes()->pending()->firstOrFail()->id;
}

// Andras's PR88-3, adopted (#104).

test('rejecting after a confirm in another tab does not claim nothing was linked', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);

    LinkCode::findOrFail($id)->confirm();   // tab 1

    // tab 2 still shows the pending row and clicks "Not mine"
    expect(fn () => $owner->linkCodes()->pending()->findOrFail($id))->toThrow(ModelNotFoundException::class)
        ->and($owner->linkCodes()->whereKey($id)->exists())->toBeTrue();

    $this->actingAs($owner);
    Volt::test('settings.linked-accounts')->call('rejectLink', $id)
        ->assertSee('already confirmed, so the account stays linked')
        ->assertDontSee('Nothing was linked');

    expect($owner->linkCodes()->whereKey($id)->exists())->toBeTrue()
        ->and(Identity::for(IdentityProvider::YouTube, 'UC-tab')->sole()->user_id)->toBe($owner->id);
});

test('"Not mine" in a stale tab is a no-op that refreshes the linked accounts list', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);
    $this->actingAs($owner);

    $staleTab = Volt::test('settings.linked-accounts')->assertSee('Yes, link it');
    Volt::test('settings.linked-accounts')->call('confirmLink', $id)->assertHasNoErrors();

    $staleTab->call('rejectLink', $id)
        ->assertSee('already confirmed')
        ->assertSee('Tuber')
        ->assertDontSee('Yes, link it');

    expect($owner->fresh()->identityFor(IdentityProvider::YouTube)?->provider_user_id)->toBe('UC-tab');
});

test('reject() refuses to delete a confirmed link and reports it', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);
    $code = LinkCode::findOrFail($id);

    $code->confirm();

    expect($code->reject())->toBeFalse()
        ->and(LinkCode::whereKey($id)->exists())->toBeTrue();
});

test('rejecting a pending link still discards it and says nothing was linked', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);

    $this->actingAs($owner);
    Volt::test('settings.linked-accounts')->call('rejectLink', $id)->assertSee('Nothing was linked');

    expect(LinkCode::whereKey($id)->exists())->toBeFalse()
        ->and(Identity::where('provider', 'youtube')->exists())->toBeFalse();
});

test('confirming in a tab after rejecting in another says it was already handled, not a 404', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);
    $this->actingAs($owner);

    Volt::test('settings.linked-accounts')->call('rejectLink', $id);
    Volt::test('settings.linked-accounts')->call('confirmLink', $id)
        ->assertHasErrors('identity')
        ->assertSee('already handled');

    expect(Identity::where('provider', 'youtube')->exists())->toBeFalse();
});

test('confirming twice from two tabs links once and refuses the second politely', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);
    $this->actingAs($owner);

    Volt::test('settings.linked-accounts')->call('confirmLink', $id)->assertHasNoErrors();
    Volt::test('settings.linked-accounts')->call('confirmLink', $id)->assertHasErrors('identity');

    expect(Identity::where('provider', 'youtube')->count())->toBe(1);
});

test('nobody can reject another user\'s pending link', function () {
    $owner = User::factory()->create();
    $id = pendingLinkFor($owner);

    $this->actingAs(User::factory()->create());
    Volt::test('settings.linked-accounts')->call('rejectLink', $id)->assertSee('already handled');

    expect(LinkCode::pending()->whereKey($id)->exists())->toBeTrue();
});
