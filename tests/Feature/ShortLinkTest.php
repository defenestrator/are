<?php

use App\Models\ShortLink;
use App\Models\ShortLinkClick;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a short link 302s to its destination with the UTM params appended and the fragment kept', function () {
    $link = ShortLink::factory()->create([
        'code' => 'ork',
        'destination' => '/about#work-with-us',
        'utm_source' => 'twitch',
        'utm_medium' => 'stream',
        'utm_campaign' => '2026-10-04-orkestera',
        'utm_content' => 'overlay',
    ]);

    $this->get('/go/ork')
        ->assertStatus(302)
        ->assertRedirect(url('/about').'?utm_source=twitch&utm_medium=stream&utm_campaign=2026-10-04-orkestera&utm_content=overlay#work-with-us');

    expect($link->fresh()->clicks)->toBe(1);
});

test('clicks from different sessions are each counted, as a counter and a dated row', function () {
    $link = ShortLink::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->flushSession();
        $this->get($link->url())->assertRedirect();
    }

    expect($link->fresh()->clicks)->toBe(3)
        ->and(ShortLinkClick::where('short_link_id', $link->id)->count())->toBe(3);
});

describe('which hits count as clicks (#73)', function () {
    test('a refresh in the same session within 30 minutes is the same click, but still redirects and refreshes attribution', function () {
        $link = ShortLink::factory()->create();

        $this->get($link->url())->assertRedirect();
        $this->travel(29)->minutes();
        $this->get($link->url())->assertRedirect()->assertSessionHas(ShortLink::SESSION_KEY.'.short_link_id', $link->id);

        expect($link->fresh()->clicks)->toBe(1)->and(ShortLinkClick::count())->toBe(1);

        $this->travel(2)->minutes(); // 31 minutes after the counted click
        $this->get($link->url())->assertRedirect();

        expect($link->fresh()->clicks)->toBe(2)->and(ShortLinkClick::count())->toBe(2);
    });

    test('dedupe is per link: the same session clicking another link counts', function () {
        [$a, $b] = ShortLink::factory()->count(2)->create();

        $this->get($a->url());
        $this->get($b->url());
        $this->get($a->url());

        expect($a->fresh()->clicks)->toBe(1)->and($b->fresh()->clicks)->toBe(1);
    });

    test('HEAD requests redirect but record nothing and leave the session alone', function () {
        $link = ShortLink::factory()->create();

        $this->call('HEAD', $link->url())->assertRedirect($link->destinationUrl())->assertSessionMissing(ShortLink::SESSION_KEY);

        expect($link->fresh()->clicks)->toBe(0)->and(ShortLinkClick::count())->toBe(0);
    });

    test('link-preview bots and scripted clients redirect but record nothing', function (string $agent) {
        $link = ShortLink::factory()->create();

        $this->withHeader('User-Agent', $agent)->get($link->url())
            ->assertRedirect($link->destinationUrl())
            ->assertSessionMissing(ShortLink::SESSION_KEY);

        expect($link->fresh()->clicks)->toBe(0)->and(ShortLinkClick::count())->toBe(0);
    })->with([
        'Discord' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
        'Slack' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
        'Slack images' => 'Slack-ImgProxy (+https://api.slack.com/robots)',
        'iMessage' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_1) AppleWebKit/601.2.4 (KHTML, like Gecko) Version/9.0.1 Safari/601.2.4 facebookexternalhit/1.1 Facebot Twitterbot/1.0',
        'Facebook' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Twitter/X' => 'Twitterbot/1.0',
        'LinkedIn' => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
        'WhatsApp' => 'WhatsApp/2.23.20.0 A',
        'Telegram' => 'TelegramBot (like TwitterBot)',
        'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Bing preview' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) BingPreview/1.0b',
        'curl' => 'curl/8.4.0',
        'python-requests' => 'python-requests/2.31.0',
        'Go' => 'Go-http-client/1.1',
        'headless Chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36',
        'no user agent' => '',
    ]);

    test('prefetches redirect but record nothing', function (string $header, string $value) {
        $link = ShortLink::factory()->create();

        $this->withHeader($header, $value)->get($link->url())->assertRedirect();

        expect($link->fresh()->clicks)->toBe(0);
    })->with([
        ['Sec-Purpose', 'prefetch;prerender'],
        ['Purpose', 'prefetch'],
        ['X-Moz', 'prefetch'],
    ]);

    test('people in browsers and in-app browsers are counted', function (string $agent) {
        $link = ShortLink::factory()->create();

        $this->withHeader('User-Agent', $agent)->get($link->url())->assertRedirect();

        expect($link->fresh()->clicks)->toBe(1);
    })->with([
        'Chrome' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'iPhone Safari' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'LinkedIn app' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [LinkedInApp]/9.29.8',
        'Facebook app' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/440.0.0.0]',
        'X app' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Twitter for iPhone/10.60',
    ]);

    test('a client that drops its session cookie is counted at most 5 times per link and IP per 30 minutes', function () {
        $link = ShortLink::factory()->create();

        foreach (range(1, 8) as $i) {
            $this->flushSession();
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($link->url())->assertRedirect();
        }

        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])->get($link->url())->assertRedirect();

        expect($link->fresh()->clicks)->toBe(ShortLink::MAX_COUNTED_PER_IP + 1)
            ->and(ShortLinkClick::count())->toBe(ShortLink::MAX_COUNTED_PER_IP + 1);
    });

    test('/go is throttled per IP: past 30 requests a minute it answers 429', function () {
        $link = ShortLink::factory()->create();

        foreach (range(1, 30) as $i) {
            $this->get($link->url())->assertRedirect();
        }

        $this->get($link->url())->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])->get($link->url())->assertRedirect();
    });

    test('the reported abuse loop no longer inflates attribution (Andras, #73)', function () {
        $link = ShortLink::for('/about', 'twitch', 'stream', '2026-10-04-show');
        $this->get('/go/'.$link->code);
        foreach (range(1, 299) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/go/'.$link->code);
        }
        $this->head('/go/'.$link->code);

        expect(ShortLinkClick::count())->toBeLessThan(100)
            ->and($link->fresh()->clicks)->toBe(ShortLinkClick::count());
    });
});

test('a click stores its attribution in the session, and the last click wins', function () {
    $first = ShortLink::factory()->create(['utm_campaign' => 'first-stream']);
    $second = ShortLink::factory()->create(['utm_campaign' => 'second-stream', 'utm_content' => 'chat']);

    $this->get($first->url())->assertSessionHas(ShortLink::SESSION_KEY, [
        'utm_source' => 'twitch',
        'utm_medium' => 'stream',
        'utm_campaign' => 'first-stream',
        'short_link_id' => $first->id,
    ]);

    $this->get($second->url())->assertSessionHas(ShortLink::SESSION_KEY, [
        'utm_source' => 'twitch',
        'utm_medium' => 'stream',
        'utm_campaign' => 'second-stream',
        'utm_content' => 'chat',
        'short_link_id' => $second->id,
    ]);
});

test('unknown codes 404 and count nothing', function () {
    $link = ShortLink::factory()->create(['code' => 'real']);

    $this->get('/go/nope')->assertNotFound();

    expect($link->fresh()->clicks)->toBe(0);
});

test('existing query strings and absolute destinations are preserved', function () {
    $link = ShortLink::factory()->create([
        'destination' => 'https://github.com/EDOS-Engineering/Orkestera?tab=readme',
        'utm_campaign' => 'launch',
    ]);

    expect($link->destinationUrl())
        ->toBe('https://github.com/EDOS-Engineering/Orkestera?tab=readme&utm_source=twitch&utm_medium=stream&utm_campaign=launch');
});

test('ShortLink::for finds the existing link for the same destination and UTM tuple', function () {
    $a = ShortLink::for('/about#work-with-us', 'twitch', 'stream', 'ep-1', 'overlay');
    $b = ShortLink::for('/about#work-with-us', 'twitch', 'stream', 'ep-1', 'overlay');
    $c = ShortLink::for('/about#work-with-us', 'twitch', 'stream', 'ep-1');
    $d = ShortLink::for('/about#work-with-us', 'twitch', 'stream', 'ep-1');

    expect($a->is($b))->toBeTrue()
        ->and($c->is($d))->toBeTrue()
        ->and($a->is($c))->toBeFalse()
        ->and(ShortLink::count())->toBe(2)
        ->and($a->url())->toBe(url('/go/'.$a->code))
        ->and($a->code)->toMatch('/^[a-z0-9]{6}$/');
});

test('short-link:create makes a link with a random or chosen code', function () {
    $this->artisan('short-link:create', ['destination' => '/about#work-with-us', '--campaign' => 'ep-1'])
        ->expectsOutputToContain('Short link: '.url('/go/'))
        ->assertSuccessful();

    $this->artisan('short-link:create', [
        'destination' => '/about#work-with-us',
        '--campaign' => 'ep-1',
        '--source' => 'youtube',
        '--content' => 'chat',
        '--code' => 'edos',
    ])->expectsOutput('Short link: '.url('/go/edos'))->assertSuccessful();

    $link = ShortLink::where('code', 'edos')->firstOrFail();

    expect(ShortLink::count())->toBe(2)
        ->and($link->utm())->toBe([
            'utm_source' => 'youtube',
            'utm_medium' => 'stream',
            'utm_campaign' => 'ep-1',
            'utm_content' => 'chat',
        ]);
});

test('short-link:create rejects a missing campaign, a bad destination and a taken code', function () {
    ShortLink::factory()->create(['code' => 'taken']);

    $this->artisan('short-link:create', ['destination' => '/about'])->assertFailed();
    $this->artisan('short-link:create', ['destination' => 'javascript:alert(1)', '--campaign' => 'x'])->assertFailed();
    $this->artisan('short-link:create', ['destination' => '/about', '--campaign' => 'x', '--code' => 'taken'])->assertFailed();
    $this->artisan('short-link:create', ['destination' => '/about', '--campaign' => 'x', '--code' => 'has space'])->assertFailed();

    expect(ShortLink::count())->toBe(1);
});

test('the database refuses a second link for the same destination and UTM tuple', function () {
    ShortLink::factory()->create(['utm_campaign' => 'ep-1', 'utm_content' => 'overlay']);

    expect(fn () => ShortLink::factory()->create(['utm_campaign' => 'ep-1', 'utm_content' => 'overlay']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a ShortLink::for call that loses a concurrent insert race returns the winner instead of a duplicate', function () {
    $hash = ShortLink::tupleHash('/about#work-with-us', 'twitch', 'stream', 'race', 'overlay');

    // Simulate another render committing the same tuple between this call's
    // lookup (which misses) and its insert: insert right after the lookup
    // query runs, outside the savepoint createOrFirst opens for its insert.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $hash) {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'short_links')) {
            return;
        }
        $raced = true;

        DB::table('short_links')->insert([
            'code' => 'winner',
            'destination' => '/about#work-with-us',
            'utm_source' => 'twitch',
            'utm_medium' => 'stream',
            'utm_campaign' => 'race',
            'utm_content' => 'overlay',
            'tuple_hash' => $hash,
            'clicks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $link = ShortLink::for('/about#work-with-us', 'twitch', 'stream', 'race', 'overlay');

    expect($raced)->toBeTrue()
        ->and($link->code)->toBe('winner')
        ->and(ShortLink::count())->toBe(1);
});

test('short-link:create refuses a custom code for a tuple that already has a link', function () {
    $existing = ShortLink::for('/about#work-with-us', 'twitch', 'stream', 'ep-1');

    $this->artisan('short-link:create', ['destination' => '/about#work-with-us', '--campaign' => 'ep-1', '--code' => 'ork'])
        ->expectsOutputToContain($existing->url())
        ->assertFailed();

    expect(ShortLink::count())->toBe(1);
});
