<?php

use App\Enums\Overlay;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;

// Smoke tests for every page, so a dependency upgrade that breaks Livewire,
// Volt or Flux rendering fails here instead of in production.

test('the home page renders for guests', function () {
    $this->get('/')->assertOk();
});

test('the vote page renders for a signed-in viewer with a populated queue', function () {
    Topic::set('Songs about tea');
    $question = Question::factory()->create(['question' => 'Earl Grey or Assam?']);

    $this->actingAs(User::factory()->create())
        ->get('/vote')
        ->assertOk()
        ->assertSee('Songs about tea')
        ->assertSee('Earl Grey or Assam?');
});

test('the settings page renders for a signed-in viewer', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertOk();
});

test('the top-vote overlay renders the leading question for guests', function () {
    $token = OverlayToken::issue(Overlay::TopVote);
    $author = User::factory()->create(['name' => 'Tea Sommelier']);
    Question::factory()->for($author)->create(['question' => 'Earl Grey or Assam?']);

    $this->get(route('overlay.show', ['overlay' => 'top-vote', 'token' => $token]))
        ->assertOk()
        ->assertSee('Earl Grey or Assam?')
        ->assertSee('Tea Sommelier');
});

test('the top-vote overlay renders with an empty queue', function () {
    $token = OverlayToken::issue(Overlay::TopVote);

    $this->get(route('overlay.show', ['overlay' => 'top-vote', 'token' => $token]))->assertOk();
});

test('the visualizer renders', function () {
    $this->get('/visualizer')->assertOk();
});

// The router ignores a trailing slash, so /vote/ serves the page; a relative
// src would then resolve to /vote/img/are.png and 404.
test('the app-layout logo uses an absolute asset URL on a nested path', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->assertSee('src="'.asset('img/are.png').'"', false)
        ->assertDontSee('src="img/are.png"', false);
})->with(['/vote/', '/settings/']);

test('the welcome page logo uses the asset URL', function () {
    $this->get('/')->assertOk()->assertSee('src="'.asset('img/are.png').'"', false);
});
