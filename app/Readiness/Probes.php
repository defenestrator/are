<?php

namespace App\Readiness;

use Illuminate\Redis\RedisManager;
use Throwable;

/**
 * The checks that touch a socket, each time-boxed. Kept apart so tests can
 * replace them (bind a subclass in the container).
 */
class Probes
{
    public const TIMEOUT_SECONDS = 2;

    /**
     * PING Redis with the configured credentials. Returns null on PONG, or
     * the error message (classified by the caller, never shown verbatim).
     */
    public function redisPing(): ?string
    {
        $config = config('database.redis');
        $connection = array_merge((array) ($config['default'] ?? []), [
            'timeout' => self::TIMEOUT_SECONDS,
            'read_timeout' => self::TIMEOUT_SECONDS,
            'read_write_timeout' => self::TIMEOUT_SECONDS,
        ]);

        try {
            // A fresh manager, so the app's own connections keep their settings.
            $manager = new RedisManager(app(), (string) ($config['client'] ?? 'phpredis'), array_merge($config, ['default' => $connection]));
            $manager->connection('default')->command('ping');

            return null;
        } catch (Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
    }

    /**
     * Whether something accepts TCP connections at $host:$port.
     */
    public function tcpReachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
