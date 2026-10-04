<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs after the application boots and before RefreshDatabase migrates,
     * so a misconfigured run stops before it can touch any database.
     */
    protected function setUpTraits()
    {
        $connection = $this->app['config']->get('database.default');

        TestDatabaseGuard::check(
            (string) $this->app->environment(),
            (string) $connection,
            $this->app['config']->get("database.connections.{$connection}"),
        );

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'services.twitch.client_id' => 'client-id',
            'services.twitch.client_secret' => 'client-secret',
            'services.twitch.broadcaster_id' => '1000',
            'services.twitch.broadcaster_ids' => ['2000'],
            'services.twitch.friend_ids' => [],
            'services.twitch.eventsub_secret' => 'eventsub-secret-for-tests',
        ]);
    }
}
