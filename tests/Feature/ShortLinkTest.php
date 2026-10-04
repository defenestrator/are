<?php

use App\Models\ShortLink;

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

test('each click is counted', function () {
    $link = ShortLink::factory()->create();

    $this->get($link->url());
    $this->get($link->url());
    $this->get($link->url());

    expect($link->fresh()->clicks)->toBe(3);
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
