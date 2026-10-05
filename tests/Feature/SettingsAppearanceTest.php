<?php

use App\Models\User;

// #167: the appearance switcher used x-model="$flux.appearance = dark", an
// assignment to an undefined variable, so Alpine threw on every load of
// /settings. It must bind to Flux's reactive $flux.appearance property.
test('the appearance switcher binds to $flux.appearance', function () {
    $html = $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->getContent();

    expect($html)->toContain('x-model="$flux.appearance"')
        ->not->toContain('$flux.appearance =');
});
