<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
