<?php

use App\Enums\Overlay;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Support\OverlayGrant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

function exchange(Overlay $overlay, mixed $token): TestResponse
{
    return test()->postJson(route('overlay.session', ['overlay' => $overlay->value]), ['token' => $token]);
}

function grantCookie(TestResponse $response, Overlay $overlay): ?Cookie
{
    return collect($response->headers->getCookies())
        ->first(fn (Cookie $cookie) => $cookie->getName() === OverlayGrant::cookieName($overlay));
}

function grantValue(TestResponse $response, Overlay $overlay): string
{
    return (string) $response->getCookie(OverlayGrant::cookieName($overlay), decrypt: true)?->getValue();
}

// The exchange endpoint

test('a valid token is exchanged for a short-lived, overlay-scoped, HttpOnly grant cookie', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $response = exchange(Overlay::Queue, $token)
        ->assertNoContent()
        ->assertHeader('Cache-Control', 'no-store, private');

    $cookie = grantCookie($response, Overlay::Queue);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getPath())->toBe('/overlay/queue')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_STRICT)
        ->and($cookie->getExpiresTime() - time())->toBeLessThanOrEqual(120)->toBeGreaterThan(0);

    $grant = json_decode(grantValue($response, Overlay::Queue), true);
    expect($grant['overlay'])->toBe('queue')
        ->and($grant['hash'])->toBe(OverlayToken::currentHash(Overlay::Queue))
        ->and($grant['expires'])->toBeLessThanOrEqual(now()->addSeconds(120)->getTimestamp());

    // Neither the cookie nor the response body carries the plaintext token.
    expect($response->getContent())->not->toContain($token)
        ->and($cookie->getValue())->not->toContain($token)
        ->and(grantValue($response, Overlay::Queue))->not->toContain($token);
});

test('the exchange refuses a wrong, missing, malformed or other overlay token, and sets no grant', function (mixed $token) {
    OverlayToken::issue(Overlay::Queue);
    $otherToken = OverlayToken::issue(Overlay::Vote);

    $response = exchange(Overlay::Queue, $token === 'OTHER' ? $otherToken : $token)->assertForbidden();

    expect(grantCookie($response, Overlay::Queue))->toBeNull();
})->with([
    'wrong' => [str_repeat('a', 64)],
    'missing' => [null],
    'empty' => [''],
    'array' => [['x']],
    'another overlay\'s token' => ['OTHER'],
]);

test('the exchange is not found for an unknown overlay', function () {
    $this->postJson('/overlay/everything/session', ['token' => 'x'])->assertNotFound();
});

test('the exchange is rate limited per address', function () {
    OverlayToken::issue(Overlay::Queue);

    foreach (range(1, 30) as $attempt) {
        exchange(Overlay::Queue, 'wrong')->assertForbidden();
    }

    exchange(Overlay::Queue, 'wrong')->assertTooManyRequests();
});

// Using the grant

test('the grant loads the overlay once, then the next load bootstraps again', function () {
    $token = OverlayToken::issue(Overlay::Queue);
    Question::factory()->create(['question' => 'Visible through the grant']);
    $grant = grantValue(exchange(Overlay::Queue, $token), Overlay::Queue);
    $name = OverlayGrant::cookieName(Overlay::Queue);

    $response = $this->withCookie($name, $grant)
        ->get(route('overlay.show', ['overlay' => 'queue', 'layout' => 'vertical']))
        ->assertOk()
        ->assertViewIs('overlays.queue')
        ->assertSee('Visible through the grant')
        ->assertSee('data-layout="vertical"', false);

    // Single use: the response expires the cookie on its own path.
    $forgotten = grantCookie($response, Overlay::Queue);
    expect($forgotten)->not->toBeNull()
        ->and($forgotten->getPath())->toBe('/overlay/queue')
        ->and($forgotten->isCleared())->toBeTrue();

    // The test client still sends the grant cookie here, as a replaying
    // client would. The server refuses it anyway: grants are claimed once.
    $this->withCookie($name, $grant)
        ->get(route('overlay.show', ['overlay' => 'queue']))
        ->assertOk()
        ->assertViewIs('overlays.bootstrap')
        ->assertDontSee('Visible through the grant');
});

test('rotating the token voids an unused grant', function () {
    $grant = grantValue(exchange(Overlay::Cta, OverlayToken::issue(Overlay::Cta)), Overlay::Cta);
    OverlayToken::issue(Overlay::Cta);

    $this->withCookie(OverlayGrant::cookieName(Overlay::Cta), $grant)
        ->get(route('overlay.show', ['overlay' => 'cta']))
        ->assertViewIs('overlays.bootstrap');
});

test('an expired grant is refused', function () {
    $grant = grantValue(exchange(Overlay::Vote, OverlayToken::issue(Overlay::Vote)), Overlay::Vote);

    $this->travel(121)->seconds();

    $this->withCookie(OverlayGrant::cookieName(Overlay::Vote), $grant)
        ->get(route('overlay.show', ['overlay' => 'vote']))
        ->assertViewIs('overlays.bootstrap');
});

test('a grant for one overlay does not open another', function () {
    $grant = grantValue(exchange(Overlay::Queue, OverlayToken::issue(Overlay::Queue)), Overlay::Queue);
    OverlayToken::issue(Overlay::Vote);

    // Even presented under the other overlay's cookie name, the payload names its overlay.
    $this->withCookie(OverlayGrant::cookieName(Overlay::Vote), $grant)
        ->get(route('overlay.show', ['overlay' => 'vote']))
        ->assertViewIs('overlays.bootstrap');
});

test('a forged, unencrypted grant cookie is ignored', function () {
    OverlayToken::issue(Overlay::Queue);
    $forged = json_encode([
        'overlay' => 'queue',
        'hash' => OverlayToken::currentHash(Overlay::Queue),
        'expires' => now()->addHour()->getTimestamp(),
    ]);

    $this->withUnencryptedCookie(OverlayGrant::cookieName(Overlay::Queue), $forged)
        ->get(route('overlay.show', ['overlay' => 'queue']))
        ->assertViewIs('overlays.bootstrap');
});

// The bootstrap page

test('the bootstrap page exchanges the fragment token and holds no overlay data', function () {
    OverlayToken::issue(Overlay::Queue);
    Question::factory()->create(['question' => 'Not on the bootstrap page']);

    $this->get(route('overlay.show', ['overlay' => 'queue', 'layout' => 'vertical']))
        ->assertOk()
        ->assertViewIs('overlays.bootstrap')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('data-layout="vertical"', false)
        ->assertSee('window.location.hash', false)
        ->assertSee(Js::from(route('overlay.session', ['overlay' => 'queue']))->toHtml(), false)
        ->assertSee('data-overlay-empty', false)
        ->assertDontSee('Not on the bootstrap page')
        ->assertDontSee('wire:poll', false);
});

test('the bootstrap page never echoes a token from the request', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $response = $this->withHeader('Referer', "https://are.example/overlay/queue#token={$token}")
        ->withCookie('unrelated', $token)
        ->get(route('overlay.show', ['overlay' => 'queue', 'layout' => $token, 'utm' => $token]))
        ->assertOk()
        ->assertViewIs('overlays.bootstrap');

    expect($response->getContent())->not->toContain($token);
});

// The deprecated ?token=

test('a ?token= still works for one release, with a deprecation warning that omits the token', function () {
    Log::spy();
    $token = OverlayToken::issue(Overlay::TopVote);

    $this->get(route('overlay.show', ['overlay' => 'top-vote', 'token' => $token]))
        ->assertOk()
        ->assertViewIs('overlays.top-vote');

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($token) {
        return str_contains($message, 'deprecated')
            && $context['overlay'] === 'top-vote'
            && ! str_contains($message.json_encode($context), $token);
    });
});

test('a wrong ?token= is still forbidden and logs nothing', function () {
    Log::spy();
    OverlayToken::issue(Overlay::TopVote);

    $this->get(route('overlay.show', ['overlay' => 'top-vote', 'token' => 'wrong']))->assertForbidden();

    Log::shouldNotHaveReceived('warning');
});

test('?token= can be switched off ahead of its removal', function () {
    config(['are.overlays.allow_query_token' => false]);
    $token = OverlayToken::issue(Overlay::TopVote);

    $this->get(route('overlay.show', ['overlay' => 'top-vote', 'token' => $token]))->assertForbidden();
});
