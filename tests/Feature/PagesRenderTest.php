<?php

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
    $author = User::factory()->create(['name' => 'Tea Sommelier']);
    Question::factory()->for($author)->create(['question' => 'Earl Grey or Assam?']);

    $this->get('/top-vote')
        ->assertOk()
        ->assertSee('Earl Grey or Assam?')
        ->assertSee('Tea Sommelier');
});

test('the top-vote overlay renders with an empty queue', function () {
    $this->get('/top-vote')->assertOk();
});

test('the visualizer renders', function () {
    $this->get('/visualizer')->assertOk();
});
