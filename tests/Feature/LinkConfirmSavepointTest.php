<?php

use App\Chat\ChatCommandRegistry;
use App\Exceptions\IdentityLinkException;
use App\Identities;
use App\IdentityProvider;
use App\Models\Identity;
use App\Models\LinkCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

// PostgreSQL aborts the whole transaction after a failed statement, so any
// query after the unique-index violation used to fail with SQLSTATE[25P02].
// SQLite and MySQL let the next query run, which is why only Postgres showed
// the 500 (#103).

/** Insert another user's identity for $channel the moment linkAccount() tries to insert its own. */
function identityWinsTheRace(User $other, string $channel): void
{
    Identity::creating(function () use ($other, $channel) {
        Identity::flushEventListeners();
        DB::table('identities')->insert(['user_id' => $other->id, 'provider' => 'youtube', 'provider_user_id' => $channel, 'created_at' => now(), 'updated_at' => now()]);
    });
}

// Andras's PR88-2, adopted (#103).

test('a concurrent link that wins the unique index gives a friendly refusal, not a database error', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $code = LinkCode::issueFor($owner);
    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, 'UC-channel', 'UC-race', 'Tuber', (string) Str::uuid(), "!link {$code}");
    $pending = $owner->linkCodes()->pending()->firstOrFail();

    identityWinsTheRace($other, 'UC-race');

    expect(fn () => $pending->confirm())->toThrow(IdentityLinkException::class, 'already linked to a different ARE account');

    // The simulated competitor inserts inside our savepoint, so it rolls back
    // with it here; a real one commits on its own connection. Either way the
    // owner gets nothing.
    expect($owner->identities()->where('provider', 'youtube')->exists())->toBeFalse();
});

test('the owner sees the refusal in Settings, not a 500', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $code = LinkCode::issueFor($owner);
    app(ChatCommandRegistry::class)->run(IdentityProvider::YouTube, 'UC-channel', 'UC-race', 'Tuber', (string) Str::uuid(), "!link {$code}");
    $pending = $owner->linkCodes()->pending()->firstOrFail();

    identityWinsTheRace($other, 'UC-race');

    $this->actingAs($owner);
    Volt::test('settings.linked-accounts')
        ->call('confirmLink', $pending->id)
        ->assertHasErrors('identity')
        ->assertSee('already linked to a different ARE account');
});

test('the transaction survives the collision, so work after it still commits', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    identityWinsTheRace($other, 'UC-race');

    DB::transaction(function () use ($owner) {
        try {
            Identities::linkAccount($owner, IdentityProvider::YouTube, 'UC-race');
        } catch (IdentityLinkException) {
            // expected
        }

        // Without the savepoint, Postgres refuses this with SQLSTATE[25P02].
        $owner->update(['name' => 'Still writable']);
    });

    expect($owner->fresh()->name)->toBe('Still writable');
});
