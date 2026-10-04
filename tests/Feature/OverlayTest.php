<?php

use App\Enums\Overlay;
use App\Enums\OverlayLayout;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;

function overlayUrl(Overlay $overlay, ?string $token, string $layout = 'horizontal'): string
{
    return route('overlay.show', array_filter([
        'overlay' => $overlay->value,
        'layout' => $layout,
        'token' => $token,
    ], fn ($value) => $value !== null));
}

function tokenFromOutput(string $output): string
{
    expect(preg_match_all('/token=([a-f0-9]{64})/', $output, $matches))->toBe(2);

    return $matches[1][0];
}

dataset('overlays', fn () => collect(Overlay::cases())->mapWithKeys(fn (Overlay $o) => [$o->value => [$o]])->all());
dataset('layouts', fn () => collect(OverlayLayout::cases())->mapWithKeys(fn (OverlayLayout $l) => [$l->value => [$l]])->all());

function configureCtas(): void
{
    config(['are.cta.items' => [
        ['key' => 'orkestera', 'eyebrow' => 'Orkestera', 'headline' => 'Agents, governed.', 'body' => 'Suite copy.', 'url' => 'https://www.orkestera.example/', 'accent' => '#7c5cff'],
        ['key' => 'edos', 'eyebrow' => 'EDOS Professional Services', 'headline' => 'Hire us.', 'body' => '', 'url' => 'https://edos.example/services', 'display_url' => 'edos.example', 'accent' => '#2dd4bf'],
    ]]);
}

// Tokens

test('an overlay without a token or grant gets only the bootstrap page', function (Overlay $overlay) {
    OverlayToken::issue($overlay);

    // Since #58 the token lives in the URL fragment, which the server never
    // sees, so a bare request gets the bootstrap page and no overlay content.
    $this->get(overlayUrl($overlay, null))
        ->assertOk()
        ->assertViewIs('overlays.bootstrap')
        ->assertDontSee('visualizer-container', false)
        ->assertDontSee('wire:poll', false)
        ->assertDontSee('data-cta', false);
})->with('overlays');

test('an overlay with the wrong token is forbidden', function () {
    OverlayToken::issue(Overlay::Queue);

    $this->get(overlayUrl(Overlay::Queue, str_repeat('a', 64)))->assertForbidden();
    $this->get(overlayUrl(Overlay::Queue, ''))->assertForbidden();
    $this->get(route('overlay.show', ['overlay' => 'queue', 'token' => ['array']]))->assertForbidden();
});

test('an overlay that has never been issued a token is forbidden', function () {
    $this->get(overlayUrl(Overlay::Queue, str_repeat('a', 64)))->assertForbidden();
});

test('a token only opens its own overlay', function () {
    $token = OverlayToken::issue(Overlay::Queue);
    OverlayToken::issue(Overlay::Vote);

    $this->get(overlayUrl(Overlay::Queue, $token))->assertOk();
    $this->get(overlayUrl(Overlay::Vote, $token))->assertForbidden();
});

test('a rotated token stops working and its replacement works', function () {
    $old = OverlayToken::issue(Overlay::Cta);
    $new = OverlayToken::issue(Overlay::Cta);

    $this->get(overlayUrl(Overlay::Cta, $old))->assertForbidden();
    $this->get(overlayUrl(Overlay::Cta, $new))->assertOk();
});

test('only a hash of the token is stored', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $row = DB::table('overlay_tokens')->first();

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($row))->not->toContain($token)
        ->and(OverlayToken::firstOrFail()->toArray())->not->toHaveKey('token_hash');
});

test('an unknown overlay is not found', function () {
    $this->get('/overlay/everything?token=x')->assertNotFound();
});

test('overlay responses keep the token out of referrers, indexes and caches', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $this->get(overlayUrl(Overlay::Queue, $token))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="referrer" content="no-referrer" />', false);
});

// Rendering

test('every overlay renders its empty state on the transparent overlay layout', function (Overlay $overlay, OverlayLayout $layout) {
    $token = OverlayToken::issue($overlay);
    $layoutsUsed = 0;
    View::composer('components.layouts.overlay', function () use (&$layoutsUsed) {
        $layoutsUsed++;
    });

    $response = $this->get(overlayUrl($overlay, $token, $layout->value))
        ->assertOk()
        ->assertViewIs($overlay->view())
        ->assertSee('data-overlay="'.$overlay->value.'"', false)
        ->assertSee('data-layout="'.$layout->value.'"', false)
        ->assertSee('bg-transparent', false)
        ->assertSee("width: {$layout->width()}px; height: {$layout->height()}px;", false)
        // No app chrome.
        ->assertDontSee('data-flux-header', false)
        ->assertDontSee('data-flux-sidebar', false);

    expect($layoutsUsed)->toBe(1);

    if ($overlay === Overlay::Visualizer) {
        $response->assertSee('id="visualizer-container"', false)->assertSee('id="vertexshader"', false);
    } else {
        $response->assertSee('data-overlay-empty', false);
    }
})->with('overlays')->with('layouts');

test('an unrecognised layout falls back to horizontal', function () {
    $token = OverlayToken::issue(Overlay::Queue);

    $this->get(overlayUrl(Overlay::Queue, $token, 'diagonal'))
        ->assertOk()
        ->assertSee('data-layout="horizontal"', false);
});

test('the queue overlay shows the newest questions, fewer on the vertical canvas', function () {
    $token = OverlayToken::issue(Overlay::Queue);
    foreach (range(1, 6) as $n) {
        Question::factory()->create(['question' => "Question number {$n}"]);
    }

    $this->get(overlayUrl(Overlay::Queue, $token))
        ->assertOk()
        ->assertSeeInOrder(['New ideas', 'Question number 6', 'Question number 2'])
        ->assertDontSee('Question number 1')
        ->assertDontSee('data-overlay-empty', false);

    $this->get(overlayUrl(Overlay::Queue, $token, 'vertical'))
        ->assertOk()
        ->assertSeeInOrder(['Question number 6', 'Question number 4'])
        ->assertDontSee('Question number 3');
});

test('the vote overlay ranks questions by votes under the current topic', function () {
    $token = OverlayToken::issue(Overlay::Vote);
    Topic::set('Songs about kale');
    $voter = User::factory()->create();
    $low = Question::factory()->create(['question' => 'Fewer votes']);
    $high = Question::factory()->create(['question' => 'More votes']);
    DB::table('question_votes')->insert(['question_id' => $high->id, 'user_id' => $voter->id, 'count' => 1]);

    $this->get(overlayUrl(Overlay::Vote, $token))
        ->assertOk()
        ->assertSeeInOrder(['Top suggestions', 'Songs about kale', 'More votes', 'Fewer votes']);
});

test('the top-vote overlay shows the leading active question', function () {
    $token = OverlayToken::issue(Overlay::TopVote);
    Question::factory()->for(User::factory()->create(['name' => 'Tea Asker']))->create(['question' => 'Sing about tea']);
    Question::factory()->create(['question' => 'Archived one', 'archived_at' => now()]);

    $this->get(overlayUrl(Overlay::TopVote, $token))
        ->assertOk()
        ->assertSee('Sing about tea')
        ->assertSee('Tea Asker')
        ->assertDontSee('Archived one');
});

// Polling

test('a polling overlay goes blank and stops polling once its token is rotated', function () {
    OverlayToken::issue(Overlay::Queue);
    Question::factory()->create(['question' => 'Visible before rotation']);

    $component = Volt::test('overlays.queue', ['layout' => 'vertical'])
        ->assertSee('Visible before rotation')
        ->assertSeeHtml('wire:poll.5s.keep-alive');

    OverlayToken::issue(Overlay::Queue);

    $component->call('$refresh')
        ->assertDontSee('Visible before rotation')
        ->assertSeeHtml('data-overlay-empty')
        ->assertDontSeeHtml('wire:poll');
});

test('a polling overlay mounted without a token shows nothing', function () {
    Question::factory()->create(['question' => 'Should not leak']);

    Volt::test('overlays.vote')->assertDontSee('Should not leak');
});

test('the captured token hash and layout cannot be changed from the browser', function (string $property) {
    OverlayToken::issue(Overlay::TopVote);

    Volt::test('overlays.top-vote')->set($property, 'tampered');
})->with(['tokenHash', 'layout'])->throws(CannotUpdateLockedPropertyException::class);

// Redirect

test('the old top-vote path redirects permanently and keeps the query', function () {
    $token = OverlayToken::issue(Overlay::TopVote);

    $this->get('/top-vote?token='.$token.'&layout=vertical')
        ->assertStatus(301)
        ->assertRedirect(route('overlay.show', ['token' => $token, 'layout' => 'vertical', 'overlay' => 'top-vote']));

    $this->followingRedirects()
        ->get('/top-vote?token='.$token.'&layout=vertical')
        ->assertOk()
        ->assertSee('data-layout="vertical"', false);
});

test('a query parameter cannot redirect top-vote to another overlay', function () {
    $this->get('/top-vote?overlay=cta')
        ->assertStatus(301)
        ->assertRedirect(route('overlay.show', ['overlay' => 'top-vote']));
});

// Call to action

test('the cta overlay rotates the configured calls to action', function (OverlayLayout $layout) {
    configureCtas();
    config(['are.cta.rotate_seconds' => 12]);
    $token = OverlayToken::issue(Overlay::Cta);

    $this->get(overlayUrl(Overlay::Cta, $token, $layout->value))
        ->assertOk()
        ->assertDontSee('data-overlay-empty', false)
        ->assertSeeInOrder(['Orkestera', 'Agents, governed.', 'orkestera.example', 'EDOS Professional Services', 'Hire us.', 'edos.example'])
        ->assertDontSee('https://www.orkestera.example')
        ->assertSee('@keyframes overlay-cta-rotate', false)
        ->assertSee('animation: overlay-cta-rotate 24s ease-in-out 0s infinite both;', false)
        ->assertSee('animation: overlay-cta-rotate 24s ease-in-out 12s infinite both;', false)
        ->assertSee('50.000%, 100% { opacity: 0;', false);
})->with('layouts');

test('a call to action without a URL is not shown', function () {
    configureCtas();
    config(['are.cta.items.1.url' => null]);
    $token = OverlayToken::issue(Overlay::Cta);

    $this->get(overlayUrl(Overlay::Cta, $token))
        ->assertOk()
        ->assertSee('Agents, governed.')
        ->assertDontSee('Hire us.')
        // A single item does not rotate.
        ->assertDontSee('@keyframes', false)
        ->assertSee('overlay-enter', false);
});

test('a call to action accent must be a hex colour', function () {
    configureCtas();
    config(['are.cta.items.0.accent' => 'red; background: url(x)']);
    $token = OverlayToken::issue(Overlay::Cta);

    $this->get(overlayUrl(Overlay::Cta, $token))
        ->assertOk()
        ->assertDontSee('url(x)', false)
        ->assertSee('--cta-accent: #7c5cff;', false);
});

// Command

test('overlay:token issues a token and prints working URLs for both layouts', function () {
    expect(Artisan::call('overlay:token', ['overlay' => 'vote']))->toBe(0);
    $output = Artisan::output();
    $token = tokenFromOutput($output);

    // The token is printed in the fragment, never the query string (#58).
    expect($output)->toContain('/overlay/vote?layout=horizontal#token='.$token)
        ->toContain('/overlay/vote?layout=vertical#token='.$token)
        ->not->toContain('?token=')
        ->not->toContain('&token=');
    $this->postJson(route('overlay.session', ['overlay' => 'vote']), ['token' => $token], ['Sec-Fetch-Site' => 'same-origin'])
        ->assertNoContent();
});

test('overlay:token refuses to replace a token without --rotate', function () {
    $token = OverlayToken::issue(Overlay::Vote);

    expect(Artisan::call('overlay:token', ['overlay' => 'vote']))->toBe(1)
        ->and(Artisan::output())->toContain('--rotate')->not->toMatch('/token=[a-f0-9]{64}/');
    $this->get(overlayUrl(Overlay::Vote, $token))->assertOk();
});

test('overlay:token --rotate replaces the token', function () {
    $old = OverlayToken::issue(Overlay::Vote);

    expect(Artisan::call('overlay:token', ['overlay' => 'vote', '--rotate' => true]))->toBe(0);
    $new = tokenFromOutput(Artisan::output());

    expect($new)->not->toBe($old);
    $this->get(overlayUrl(Overlay::Vote, $old))->assertForbidden();
    $this->get(overlayUrl(Overlay::Vote, $new))->assertOk();
});

test('overlay:token rejects an unknown overlay', function () {
    expect(Artisan::call('overlay:token', ['overlay' => 'everything']))->toBe(2)
        ->and(Artisan::output())->toContain('queue, vote, now-playing, captions, visualizer, top-vote, cta');
    expect(OverlayToken::count())->toBe(0);
});
