<?php

use App\Models\Lead;
use App\Models\ShortLink;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

beforeEach(function () {
    RateLimiter::clear('lead-form:'.sha1('127.0.0.1'));
});

function fillLeadForm(): \Livewire\Features\SupportTesting\Testable
{
    return Volt::test('lead-form')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('company', 'Analytical Engines')
        ->set('message', 'We want help running agentic workflows in production.')
        ->set('consent', true);
}

test('the about page is public and has the work-with-us form', function () {
    $this->get('/about')
        ->assertOk()
        ->assertSee(__('about.who.heading'))
        ->assertSee(__('about.orkestera.heading'))
        ->assertSee('id="work-with-us"', false)
        ->assertSeeLivewire('lead-form');
});

test('a viewer goes from a stream short link to a stored, attributed enquiry in two clicks', function () {
    $this->freezeTime();

    $link = ShortLink::for('/about#work-with-us', 'twitch', 'stream', '2026-10-04-orkestera-live', 'overlay');

    // Click 1: the link shown on stream lands on the enquiry form.
    $this->get($link->url())->assertRedirectContains('/about?')->assertRedirectContains('#work-with-us');
    $this->get('/about')->assertOk()->assertSeeLivewire('lead-form');

    // Click 2: submit.
    fillLeadForm()->call('submit')->assertHasNoErrors()->assertSet('submitted', true);

    $lead = Lead::sole();

    expect($lead->name)->toBe('Ada Lovelace')
        ->and($lead->email)->toBe('ada@example.com')
        ->and($lead->company)->toBe('Analytical Engines')
        ->and($lead->consented_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($lead->shortLink->is($link))->toBeTrue()
        ->and($lead->utm_source)->toBe('twitch')
        ->and($lead->utm_medium)->toBe('stream')
        ->and($lead->utm_campaign)->toBe('2026-10-04-orkestera-live')
        ->and($lead->utm_content)->toBe('overlay');
});

test('an enquiry without a short link click is stored unattributed', function () {
    fillLeadForm()->set('company', '')->call('submit')->assertHasNoErrors();

    $lead = Lead::sole();

    expect($lead->company)->toBeNull()
        ->and($lead->short_link_id)->toBeNull()
        ->and($lead->utm_campaign)->toBeNull();
});

test('consent is required and nothing is stored without it', function () {
    fillLeadForm()->set('consent', false)
        ->call('submit')
        ->assertHasErrors(['consent' => 'accepted'])
        ->assertSet('submitted', false);

    expect(Lead::count())->toBe(0);
});

test('name, a valid email and a message are required', function () {
    Volt::test('lead-form')
        ->set('email', 'not-an-email')
        ->set('message', 'short')
        ->set('consent', true)
        ->call('submit')
        ->assertHasErrors(['name' => 'required', 'email' => 'email', 'message' => 'min']);

    expect(Lead::count())->toBe(0);
});

test('a filled honeypot looks like success but stores nothing', function () {
    fillLeadForm()->set('website', 'http://spam.example')
        ->call('submit')
        ->assertSet('submitted', true);

    expect(Lead::count())->toBe(0);
});

test('enquiries are rate-limited per IP address', function () {
    foreach (range(1, 5) as $i) {
        fillLeadForm()->call('submit')->assertHasNoErrors();
    }

    fillLeadForm()->call('submit')->assertHasErrors('form')->assertSet('submitted', false);

    expect(Lead::count())->toBe(5);
});
