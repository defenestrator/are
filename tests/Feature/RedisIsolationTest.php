<?php

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/**
 * Swap the Redis client for one that records each connection's resolved
 * config instead of opening a socket, so tests run without a Redis server.
 *
 * @return ArrayObject<string, array{config: array<string, mixed>, options: array<string, mixed>, connection: Connection&MockInterface}>
 */
function recordRedisConnections(): ArrayObject
{
    $connections = new ArrayObject;

    // Rebuild the manager and the cache store so they read the current config.
    app()->forgetInstance('redis');
    Cache::forgetDriver('redis');

    /** @var RedisManager $redis */
    $redis = app('redis');
    $redis->setDriver('recording');
    $redis->extend('recording', fn () => new class($connections) implements Connector
    {
        /** @param  ArrayObject<string, mixed>  $connections */
        public function __construct(private ArrayObject $connections) {}

        public function connect(array $config, array $options)
        {
            $connection = Mockery::spy(Connection::class);
            $this->connections[(string) $config['database']] = compact('config', 'options', 'connection');

            return $connection;
        }

        public function connectToCluster(array $config, array $clusterOptions, array $options)
        {
            throw new LogicException('ARE does not use Redis Cluster.');
        }
    });

    return $connections;
}

test('ARE keeps its Redis data off the indexes and prefixes other apps default to', function () {
    // Defaults must not depend on APP_NAME, which other apps also leave as "Laravel".
    expect(config('database.redis.default.database'))->toBe('4')
        ->and(config('database.redis.cache.database'))->toBe('5')
        ->and(config('database.redis.options.prefix'))->toBe('are_database_')
        ->and(config('cache.prefix'))->toBe('are_cache_')
        ->and(config('horizon.prefix'))->toBe('are_horizon:')
        ->and(config('horizon.use'))->toBe('default')
        ->and(config('queue.connections.redis.connection'))->toBe('default');
});

test('the redis cache store connects with the configured cache DB and prefixes', function () {
    config([
        'database.redis.cache.database' => '9',
        'database.redis.options.prefix' => 'custom_database_',
        'cache.prefix' => 'custom_cache_',
    ]);
    $connections = recordRedisConnections();

    $store = Cache::store('redis')->getStore();
    $store->connection();

    expect($connections)->toHaveKey('9')
        ->and($connections['9']['options']['prefix'])->toBe('custom_database_')
        ->and($store->getPrefix())->toBe('custom_cache_');
});

test('cache:clear on the redis store flushes only the cache DB, never the queue DB', function () {
    config(['cache.default' => 'redis']);
    $connections = recordRedisConnections();

    // Resolve the queue and Horizon connection too, so a stray flush would be caught.
    $queue = app('redis')->connection('default');
    $queue->shouldNotReceive('flushdb');

    $this->artisan('cache:clear')->assertSuccessful();

    expect(array_keys($connections->getArrayCopy()))->toEqualCanonicalizing(['4', '5']);
    $connections['5']['connection']->shouldHaveReceived('flushdb')->once();
});
