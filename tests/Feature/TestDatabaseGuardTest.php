<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestDatabaseGuard;

// Guards against #84: RefreshDatabase runs migrate:fresh, so a test run that
// reaches a real database destroys it.

const GUARD_TARGET = 'the guard accepts the database this run is pinned to';

/**
 * Run GUARD_TARGET in a child Pest process with extra environment variables.
 *
 * @param  array<string, string>  $env
 */
/**
 * A second *_test database for the child runs, created on first use. It is
 * created over its own connection: CREATE DATABASE cannot run inside the
 * transaction RefreshDatabase wraps this test in.
 */
function guardChildDatabase(): string
{
    $name = config('database.connections.pgsql.database').'_guard_test';

    config(['database.connections.guard_admin' => config('database.connections.pgsql')]);
    $admin = DB::connection('guard_admin');

    if ($admin->scalar('select count(*) from pg_database where datname = ?', [$name]) == 0) {
        $admin->statement('create database '.$admin->getQueryGrammar()->wrap($name));
    }

    DB::purge('guard_admin');

    return $name;
}

function runGuardedSuite(array $env): ProcessResult
{
    // Default the child to its own database. Otherwise it would inherit
    // are_test and migrate:fresh the database this parent run is using.
    $env += ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => guardChildDatabase()];

    return Process::path(base_path())
        ->env($env)
        ->timeout(120)
        ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/TestDatabaseGuardTest.php', '--filter='.GUARD_TARGET]);
}

test(GUARD_TARGET, function () {
    $connection = config('database.default');

    TestDatabaseGuard::check(app()->environment(), $connection, config("database.connections.{$connection}"));

    expect(true)->toBeTrue();
});

test('the guard allows PostgreSQL *_test databases', function (array $connection) {
    TestDatabaseGuard::check('testing', 'x', $connection);

    expect(true)->toBeTrue();
})->with([
    'pgsql are_test' => [['driver' => 'pgsql', 'database' => 'are_test']],
    'url naming are_test' => [['driver' => 'pgsql', 'url' => 'pgsql://u:p@127.0.0.1:5432/are_test', 'database' => 'are']],
]);

test('the guard refuses a non-test database', function (string $environment, ?array $connection, string $reason) {
    expect(fn () => TestDatabaseGuard::check($environment, 'x', $connection))
        ->toThrow(RuntimeException::class, $reason);
})->with([
    'production postgres' => ['testing', ['driver' => 'pgsql', 'database' => 'are'], "database 'are'"],
    'DB_URL overriding a pinned *_test name' => ['testing', ['driver' => 'pgsql', 'url' => 'pgsql://u:p@127.0.0.1:5432/are', 'database' => 'are_test'], "database 'are'"],
    'a sqlite file' => ['testing', ['driver' => 'sqlite', 'database' => '/srv/are/database.sqlite'], 'database.sqlite'],
    'sqlite :memory:' => ['testing', ['driver' => 'sqlite', 'database' => ':memory:'], "sqlite database ':memory:'"],
    'a cached production config' => ['production', ['driver' => 'pgsql', 'database' => 'are_test'], "APP_ENV is 'production'"],
    'an unconfigured connection' => ['testing', null, 'not configured'],
]);

test('phpunit.xml never points the suite at a cached config', function () {
    expect(app()->getCachedConfigPath())->not->toBe(base_path('bootstrap/cache/config.php'))
        ->and(file_exists(app()->getCachedConfigPath()))->toBeFalse()
        ->and(app()->configurationIsCached())->toBeFalse();
});

test('a run pointed at a non-test database stops before connecting', function () {
    // Port 1 has nothing listening: if the guard let it through, the error
    // would be a connection failure, not the refusal.
    $result = runGuardedSuite([
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_DATABASE' => 'are',
    ]);

    expect($result->successful())->toBeFalse()
        ->and($result->output())->toContain("Refusing to run tests: connection 'pgsql' uses pgsql database 'are'")
        ->and($result->output())->not->toContain('Connection refused');
});

test('a DB_URL in the shell cannot redirect the suite', function () {
    $result = runGuardedSuite(['DB_URL' => 'pgsql://are:are@127.0.0.1:1/are']);

    expect($result->output())->toContain('1 passed')
        ->and($result->successful())->toBeTrue();
});

test('a cached production config is not loaded under test', function () {
    $cache = sys_get_temp_dir().'/are-84-'.bin2hex(random_bytes(4)).'-config.php';

    try {
        Process::path(base_path())->env([
            'APP_CONFIG_CACHE' => $cache,
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',   // unreachable, so even a regression cannot touch a real server
            'DB_DATABASE' => 'are',
        ])->run([PHP_BINARY, 'artisan', 'config:cache'])->throw();
        expect(file_exists($cache))->toBeTrue();

        $result = runGuardedSuite(['APP_CONFIG_CACHE' => $cache]);

        expect($result->output())->toContain('1 passed')
            ->and($result->successful())->toBeTrue();
    } finally {
        @unlink($cache);
    }
});
