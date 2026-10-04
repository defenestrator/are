<?php

/*
 * Adopted from Andras's review of PR #74 (/tmp/andras-tests/AndrasPr74Test.php).
 *
 * Laravel skips CSRF verification under unit tests, which hid this bug: OBS
 * browser sources share one cookie jar, so overlays bootstrapping together
 * overwrite each other's session, and with it the CSRF token. Every exchange
 * except the last got a 419 and gave up. These tests run the real check.
 *
 * Changes from his file: the control that a wrong CSRF token gets a 419 now
 * asserts the opposite, because the exchange is exempt; a control proves the
 * strict check is still active elsewhere; requests send Sec-Fetch-Site as a
 * browser does; and the dump() is gone.
 */

use App\Enums\Overlay;
use App\Models\OverlayToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/** The real CSRF check: Laravel skips it under unit tests. */
class AndrasStrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

beforeEach(function () {
    $this->app->bind(ValidateCsrfToken::class, AndrasStrictCsrf::class);
});

const SAME_ORIGIN = ['Sec-Fetch-Site' => 'same-origin'];

test('control: the strict CSRF check is active on other POST routes', function () {
    Route::post('/_csrf-probe', fn () => 'ok')->middleware('web');

    $this->post('/_csrf-probe')->assertStatus(419);
});

test('one overlay alone exchanges its token with the real CSRF check', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $page = $this->get('/overlay/queue');
    $session = $page->getCookie('laravel_session')->getValue();

    $this->withCookie('laravel_session', $session)
        ->postJson('/overlay/queue/session', ['token' => $token], SAME_ORIGIN)
        ->assertNoContent();
});

test('the exchange needs no CSRF token: a wrong one or none still succeeds', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $this->postJson('/overlay/queue/session', ['token' => $token], [...SAME_ORIGIN, 'X-CSRF-TOKEN' => 'nope'])
        ->assertNoContent();
    $this->postJson('/overlay/queue/session', ['token' => $token], SAME_ORIGIN)
        ->assertNoContent();
});

test('two OBS sources starting together (one cookie jar) both get their grant', function () {
    $queueToken = OverlayToken::issue(Overlay::Queue);
    $voteToken = OverlayToken::issue(Overlay::Vote);

    // Both bootstraps load before either has a session cookie, as when OBS starts.
    $this->get('/overlay/queue')->assertViewIs('overlays.bootstrap');
    // Each real request starts with an empty session store; the test app reuses one.
    app('session')->driver()->flush();
    $votePage = $this->get('/overlay/vote')->assertViewIs('overlays.bootstrap');

    // The shared jar keeps the last Set-Cookie: the vote page's session.
    $jarSession = $votePage->getCookie('laravel_session')->getValue();

    $this->withCookie('laravel_session', $jarSession)
        ->postJson('/overlay/queue/session', ['token' => $queueToken], SAME_ORIGIN)
        ->assertNoContent();
    $this->withCookie('laravel_session', $jarSession)
        ->postJson('/overlay/vote/session', ['token' => $voteToken], SAME_ORIGIN)
        ->assertNoContent();
});

test('the bootstrap page no longer embeds a session-bound CSRF token', function () {
    OverlayToken::issue(Overlay::Queue);

    $this->get('/overlay/queue')
        ->assertViewIs('overlays.bootstrap')
        ->assertDontSee('X-CSRF-TOKEN', false)
        ->assertDontSee(csrf_token(), false);
});
