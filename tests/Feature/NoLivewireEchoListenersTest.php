<?php

use App\Enums\Overlay;
use App\Models\OverlayToken;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;
use Livewire\Volt\Volt;

// #125: Livewire logs "Laravel Echo cannot be found" once per mounted
// component that declares an `echo:` listener whenever window.Echo is
// undefined, which is production's state without Reverb, and OBS writes it to
// its log on every overlay load. Live updates go through resources/js/
// live-queue.js and live-overlay.js instead, which know whether Echo exists.

dataset('reverb builds', [
    'without a Reverb key' => [['broadcasting.default' => 'log', 'reverb.apps.apps.0.key' => null]],
    'with a Reverb key' => [['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']],
]);

test('the vote page renders no Livewire echo listener', function (array $config) {
    config($config);
    Question::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get('/vote')
        ->assertOk()
        ->assertSeeHtml('x-data="liveQueue"')
        ->assertDontSee('echo:', false)
        ->assertDontSee('echo-', false);
})->with('reverb builds');

test('the overlays render no Livewire echo listener', function (array $config, Overlay $overlay) {
    config($config);
    Question::factory()->create();
    $token = OverlayToken::issue($overlay);

    $this->get(route('overlay.show', ['overlay' => $overlay->value, 'token' => $token]))
        ->assertOk()
        ->assertDontSee('echo:', false)
        ->assertDontSee('echo-', false);
})->with('reverb builds')->with(fn () => Overlay::cases());

test('no Livewire component in the app declares an echo listener', function () {
    // Guards against a new #[On('echo:…')] or getListeners() entry bringing
    // the warning back. Browser-side subscriptions belong in resources/js.
    $offenders = collect(File::allFiles(resource_path('views')))
        ->merge(File::allFiles(app_path()))
        ->filter(fn ($file) => preg_match('/[\'"]echo(-[a-z]+)?:/', $file->getContents()) === 1)
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('the old Livewire topic listeners are gone', function (Closure $component) {
    $component()->dispatch('echo:topic,TopicChanged', ['topic' => 'Kale']);
})->with([
    'vote page' => [function () {
        test()->actingAs(User::factory()->create());

        return Livewire::test(FragmentAlias::encode('vote', resource_path('views/vote.blade.php')));
    }],
    'topic component' => [function () {
        test()->actingAs(User::factory()->create());

        return Volt::test('topic');
    }],
    'vote overlay' => [function () {
        OverlayToken::issue(Overlay::Vote);

        return Volt::test('overlays.vote');
    }],
])->throws(EventHandlerDoesNotExist::class);

// topic-sync arrives on every fallback poll, so it must not disturb a
// moderator who is typing a new topic.

function topicModerator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

test('a routine topic-sync leaves a moderator\'s unsaved topic alone', function () {
    Topic::set('Current topic');
    $this->actingAs(topicModerator());

    Volt::test('topic')
        ->set('topic', 'Half-typed new top')
        ->dispatch('topic-sync')
        ->assertSet('topic', 'Half-typed new top');
});

test('a topic-sync after another moderator changed the topic shows their topic', function () {
    Topic::set('Current topic');
    $this->actingAs(topicModerator());
    $component = Volt::test('topic')->set('topic', 'Half-typed');

    Topic::set('Set by someone else');

    $component->dispatch('topic-sync')
        ->assertSet('topic', 'Set by someone else')
        ->assertSee('Set by someone else');
});

test('a moderator\'s own save or clear is not undone by the next topic-sync', function () {
    Topic::set('Current topic');
    $this->actingAs(topicModerator());

    Volt::test('topic')
        ->set('topic', 'My new topic')
        ->call('save')
        ->dispatch('topic-sync')
        ->assertSet('topic', 'My new topic')
        ->call('clear')
        ->dispatch('topic-sync')
        ->assertSet('topic', '');
});
