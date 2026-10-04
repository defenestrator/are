<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

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

    /**
     * Keep the cookies a response set for this test's later requests,
     * including Livewire and Volt tests, as a browser would. Laravel's test
     * client does not carry cookies between requests by itself.
     */
    protected function keepCookies(TestResponse $response): static
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $value = $response->getCookie($cookie->getName())?->getValue();

            if ($cookie->isCleared() || $value === null) {
                unset($this->defaultCookies[$cookie->getName()]);

                continue;
            }

            $this->withCookie($cookie->getName(), $value);
            Livewire::withCookie($cookie->getName(), $value);
        }

        return $this;
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
