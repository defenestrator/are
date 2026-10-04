<?php

use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

/**
 * @param  array<string, string|null>  $avatars  provider => avatar URL, in link order
 */
function personWith(array $avatars, string $name = 'FB Person'): User
{
    $factory = User::factory();
    foreach (array_keys($avatars) as $provider) {
        $factory = $factory->{$provider}();
    }
    $user = $factory->create(['name' => $name]);

    foreach ($avatars as $provider => $url) {
        $user->identities()->where('provider', $provider)->update(['avatar_url' => $url]);
    }

    return $user->fresh();
}

// Andras's repro (#35), on identities.

test('a Facebook-only author gets an avatar on the question card', function () {
    $fb = personWith(['facebook' => 'https://graph.facebook.com/fb-1/picture']);
    Question::factory()->for($fb)->create();
    $question = Question::getSortedQuestions()->sole();

    $this->actingAs(User::factory()->create());
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->assertSee('https://graph.facebook.com/fb-1/picture', false)
        ->assertDontSee('src=""', false);
});

test('the avatar comes from the oldest identity that has one', function () {
    expect(personWith(['twitch' => 'https://twitch.example/a.png', 'facebook' => 'https://fb.example/b.png'])->avatar_url)
        ->toBe('https://twitch.example/a.png')
        ->and(personWith(['facebook' => 'https://fb.example/b.png', 'twitch' => 'https://twitch.example/a.png'])->avatar_url)
        ->toBe('https://fb.example/b.png')
        ->and(personWith(['twitch' => null, 'youtube' => 'https://yt.example/c.png'])->avatar_url)
        ->toBe('https://yt.example/c.png')
        ->and(personWith(['facebook' => ''])->avatar_url)
        ->toBeNull();
});

test('an author with no picture gets initials on the question card, not a blank image', function () {
    $author = personWith(['facebook' => null], 'Kale Fan');
    Question::factory()->for($author)->create();
    $question = Question::getSortedQuestions()->sole();

    $this->actingAs(User::factory()->create());
    Volt::test('question-card', ['question' => $question, 'voteCount' => 0, 'userVotes' => []])
        ->assertSee('KF')
        ->assertDontSee('src=""', false);
});

test('the overlay card shows a Facebook-only author\'s avatar, or their initial', function () {
    Question::factory()->for(personWith(['facebook' => 'https://graph.facebook.com/fb-1/picture']))->create(['question' => 'With a picture']);
    Question::factory()->for(personWith(['facebook' => null], 'Zed'))->create(['question' => 'Without one']);
    $questions = Question::getSortedQuestions()->keyBy('question');

    expect(Blade::render('<x-overlay.question-card :question="$q" />', ['q' => $questions['With a picture']]))
        ->toContain('src="https://graph.facebook.com/fb-1/picture"');

    $html = Blade::render('<x-overlay.question-card :question="$q" />', ['q' => $questions['Without one']]);
    expect($html)->not->toContain('<img')->toContain('Z');
});

test('the header shows a Facebook-only viewer\'s avatar, or initials', function () {
    $this->actingAs(personWith(['facebook' => 'https://graph.facebook.com/fb-1/picture']))
        ->get('/vote')->assertOk()
        ->assertSee('https://graph.facebook.com/fb-1/picture', false)
        ->assertDontSee('<img src="" />', false);

    $this->actingAs(personWith(['facebook' => null], 'Kale Fan'))
        ->get('/settings')->assertOk()
        ->assertDontSee('<img src="" />', false);
});

test('avatars on a full queue add no queries per card', function () {
    $queriesFor = function (int $count) {
        Question::query()->delete();
        Question::factory()->count($count)->for(personWith(['facebook' => 'https://fb.example/p.png']))->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $questions = Question::getSortedQuestions();
        $questions->each(fn (Question $q) => $q->user->avatar_url);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(20))->toBe($queriesFor(1));
});
