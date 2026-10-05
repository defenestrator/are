<?php

namespace Tests;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

/**
 * Refuses to let the suite touch a database that is not obviously a test
 * database. RefreshDatabase runs migrate:fresh, so pointing the suite at a
 * real database (through a cached config, a DB_URL, or a stray DB_DATABASE)
 * would wipe it.
 */
final class TestDatabaseGuard
{
    /**
     * @param  array<string, mixed>|null  $connection  The connection's config, before URL parsing.
     */
    public static function check(string $environment, string $connectionName, ?array $connection): void
    {
        if ($environment !== 'testing') {
            self::refuse("APP_ENV is '{$environment}', not 'testing'. Is a cached config (bootstrap/cache/config.php) being loaded?");
        }

        if ($connection === null) {
            self::refuse("the default connection '{$connectionName}' is not configured.");
        }

        // Resolve DB_URL the same way DatabaseManager does, so a URL cannot
        // smuggle in a different database name than the one checked here.
        $resolved = (new ConfigurationUrlParser)->parseConfiguration($connection);
        $driver = (string) ($resolved['driver'] ?? '');
        $database = (string) ($resolved['database'] ?? '');

        if ($driver === 'pgsql' && str_ends_with($database, '_test')) {
            return;
        }

        self::refuse("connection '{$connectionName}' uses {$driver} database '{$database}'. Tests run only on a PostgreSQL database whose name ends in _test.");
    }

    private static function refuse(string $reason): never
    {
        throw new RuntimeException("Refusing to run tests: {$reason}");
    }
}
