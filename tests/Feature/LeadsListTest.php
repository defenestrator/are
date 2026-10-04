<?php

use App\Models\Lead;
use App\Models\ShortLink;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;

function leadsBroadcaster(): User
{
    // TestCase sets the primary broadcaster id to 1000.
    return User::factory()->create(['twitch_id' => '1000']);
}

function leadsModerator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

function leadsFragment(): Testable
{
    return Livewire::test(FragmentAlias::encode('leads', resource_path('views/leads.blade.php')));
}

test('guests are sent to log in', function () {
    $this->get('/leads')->assertRedirect();
});

test('viewers and moderators get a 403: leads are for broadcasters only', function () {
    $this->actingAs(User::factory()->create())->get('/leads')->assertForbidden();
    $this->actingAs(leadsModerator())->get('/leads')->assertForbidden();
});

test('a banned broadcaster account cannot read leads', function () {
    $broadcaster = leadsBroadcaster();
    TwitchBan::create(['broadcaster_id' => '2000', 'twitch_user_id' => $broadcaster->twitch_id]);

    expect($broadcaster->can('viewAny', Lead::class))->toBeFalse();
});

test('a broadcaster sees leads with their attribution, newest first', function () {
    $link = ShortLink::for('/about#work-with-us', 'twitch', 'stream', '2026-10-04-orkestera-live', 'overlay');
    Lead::factory()->create(['name' => 'Older Lead', 'created_at' => now()->subDay()]);
    Lead::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'short_link_id' => $link->id,
        'utm_source' => 'twitch',
        'utm_medium' => 'stream',
        'utm_campaign' => '2026-10-04-orkestera-live',
        'utm_content' => 'overlay',
    ]);

    $this->actingAs(leadsBroadcaster())->get('/leads')
        ->assertOk()
        ->assertSeeInOrder(['Ada Lovelace', 'Older Lead'])
        ->assertSee('ada@example.com')
        ->assertSee('twitch / stream / overlay')
        ->assertSee('2026-10-04-orkestera-live')
        ->assertSee('/go/'.$link->code)
        ->assertSee('Direct');
});

test('the list is paginated', function () {
    Lead::factory()->count(30)->sequence(fn ($s) => ['name' => 'Lead '.str_pad((string) $s->index, 2, '0', STR_PAD_LEFT)])->create();

    $this->actingAs(leadsBroadcaster());

    leadsFragment()
        ->assertSee('Lead 29')
        ->assertDontSee('Lead 04')
        ->call('gotoPage', 2)
        ->assertSee('Lead 04')
        ->assertDontSee('Lead 29');
});

test('the Livewire endpoint re-checks the policy on every request', function () {
    $this->actingAs(leadsModerator());

    leadsFragment()->assertForbidden();
});

test('only broadcasters see the Leads nav link', function () {
    $this->actingAs(leadsModerator())->get('/vote')->assertOk()->assertDontSee(route('leads.index'));
    $this->actingAs(leadsBroadcaster())->get('/vote')->assertOk()->assertSee(route('leads.index'));
});
