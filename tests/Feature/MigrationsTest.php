<?php

use Illuminate\Support\Facades\Schema;

// RefreshDatabase has already run every migration on the PostgreSQL test
// database (the equivalent of migrate:fresh), so this starts fully migrated.
test('every migration rolls back to an empty schema and applies again', function () {
    $this->artisan('migrate:reset')->assertSuccessful();
    expect(Schema::getTableListing(schemaQualified: false))->toBe(['migrations']);

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('questions'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email'))->toBeTrue()
        ->and(Schema::hasColumns('identities', ['provider', 'provider_user_id', 'user_id']))->toBeTrue();
});

test('rolling back the subscriptions migration restores the users columns it dropped (#121)', function () {
    $migration = require database_path('migrations/2025_03_04_142526_create_user_twitch_subscriptions_table.php');
    $dropped = ['poki_sub', 'twitch_access_token', 'twitch_refresh_token', 'twitch_expires_in', 'twitch_subscription'];
    expect(collect($dropped)->filter(fn (string $column) => Schema::hasColumn('users', $column)))->toBeEmpty();

    $migration->down();

    expect(Schema::hasTable('user_twitch_subscriptions'))->toBeFalse()
        ->and(Schema::hasColumns('users', $dropped))->toBeTrue();

    // And up() takes them away again, so the pair is a true round trip.
    $migration->up();

    expect(Schema::hasTable('user_twitch_subscriptions'))->toBeTrue()
        ->and(collect($dropped)->filter(fn (string $column) => Schema::hasColumn('users', $column)))->toBeEmpty();
});
