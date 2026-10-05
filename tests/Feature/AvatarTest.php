<?php

use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, string|null>  $avatars  provider => avatar URL, in link order
 */
function personWith(array $avatars, string $name = 'YT Person'): User
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

test('a YouTube-only author gets an avatar on the question card', function () {
    $tuber = personWith(['youtube' => 'https://yt3.ggpht.com/yt-1=s88']);
    Question::factory()->for($tuber)->create();
    $question = Question::getSortedQuestions()->sole();

    $this->actingAs(User::factory()->create());
    expect(cardHtml($question))->toContain('https://yt3.ggpht.com/yt-1=s88')->not->toContain('src=""');
});

test('the avatar comes from the oldest identity that has one', function () {
    expect(personWith(['twitch' => 'https://twitch.example/a.png', 'youtube' => 'https://yt.example/b.png'])->avatar_url)
        ->toBe('https://twitch.example/a.png')
        ->and(personWith(['youtube' => 'https://yt.example/b.png', 'twitch' => 'https://twitch.example/a.png'])->avatar_url)
        ->toBe('https://yt.example/b.png')
        ->and(personWith(['twitch' => null, 'youtube' => 'https://yt.example/c.png'])->avatar_url)
        ->toBe('https://yt.example/c.png')
        ->and(personWith(['youtube' => ''])->avatar_url)
        ->toBeNull();
});

test('an author with no picture gets initials on the question card, not a blank image', function () {
    $author = personWith(['youtube' => null], 'Kale Fan');
    Question::factory()->for($author)->create();
    $question = Question::getSortedQuestions()->sole();

    $this->actingAs(User::factory()->create());
    expect(cardHtml($question))->toContain('KF')->not->toContain('src=""');
});

test('the overlay card shows a YouTube-only author\'s avatar, or their initial', function () {
    Question::factory()->for(personWith(['youtube' => 'https://yt3.ggpht.com/yt-1=s88']))->create(['question' => 'With a picture']);
    Question::factory()->for(personWith(['youtube' => null], 'Zed'))->create(['question' => 'Without one']);
    $questions = Question::getSortedQuestions()->keyBy('question');

    expect(Blade::render('<x-overlay.question-card :question="$q" />', ['q' => $questions['With a picture']]))
        ->toContain('src="https://yt3.ggpht.com/yt-1=s88"');

    $html = Blade::render('<x-overlay.question-card :question="$q" />', ['q' => $questions['Without one']]);
    expect($html)->not->toContain('<img')->toContain('Z');
});

test('the header shows a YouTube-only viewer\'s avatar, or initials', function () {
    $this->actingAs(personWith(['youtube' => 'https://yt3.ggpht.com/yt-1=s88']))
        ->get('/vote')->assertOk()
        ->assertSee('https://yt3.ggpht.com/yt-1=s88', false)
        ->assertDontSee('<img src="" />', false);

    $this->actingAs(personWith(['youtube' => null], 'Kale Fan'))
        ->get('/settings')->assertOk()
        ->assertDontSee('<img src="" />', false);
});

test('avatars on a full queue add no queries per card', function () {
    $queriesFor = function (int $count) {
        Question::query()->delete();
        Question::factory()->count($count)->for(personWith(['youtube' => 'https://yt.example/p.png']))->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $questions = Question::getSortedQuestions();
        $questions->each(fn (Question $q) => $q->user->avatar_url);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(20))->toBe($queriesFor(1));
});
