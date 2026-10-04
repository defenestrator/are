<?php

use Illuminate\Support\Facades\Schema;

// RefreshDatabase has already run every migration on the in-memory SQLite
// database (the equivalent of migrate:fresh), so this starts fully migrated.
test('every migration rolls back to an empty schema and applies again', function () {
    $this->artisan('migrate:reset')->assertSuccessful();
    expect(Schema::getTableListing(schemaQualified: false))->toBe(['migrations']);

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('questions'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email'))->toBeTrue()
        ->and(Schema::hasColumns('identities', ['provider', 'provider_user_id', 'user_id']))->toBeTrue();
});
