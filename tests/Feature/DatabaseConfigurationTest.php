<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('has no configured SQLite runtime connection', function () {
    expect(config('database.connections.sqlite'))->toBeNull();

    expect(fn () => DB::connection('sqlite'))
        ->toThrow(InvalidArgumentException::class, 'Database connection [sqlite] not configured.');
});

it('uses PostgreSQL for the application test database', function () {
    expect(config('database.default'))->toBe('pgsql')
        ->and(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('are_test');
});

it('has retired the legacy SQLite importer', function () {
    expect(Artisan::all())->not->toHaveKey('db:copy');
});
