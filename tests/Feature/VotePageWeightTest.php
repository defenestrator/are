<?php

use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Testing\TestResponse;

// #180: /vote rendered every card as its own Livewire component. With a full
// queue that was 102 components, about 300 ms of CPU and 923 KB per load.
// Cards are Blade components now, and their icons come from one sprite.
// These pin what can be pinned deterministically; CPU time can't be, so the
// PR reports it, measured the way Andras measured it.

function fullVotePage(bool $moderator = false): TestResponse
{
    $viewer = User::factory()->twitch('42')->create();
    if ($moderator) {
        TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
    }
    Topic::set('Songs about kale');
    Question::factory()->count(50)->for(User::factory())->create(['question' => 'A question of an ordinary length, about kale']);
    // Factory questions skip QuestionQueue, which would retire the cached queue.
    Question::forgetCachedQueue();

    return test()->actingAs($viewer)->get('/vote')->assertOk();
}

function livewireComponents(string $html): array
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $snapshots);

    return array_map(fn ($s) => html_entity_decode($s), $snapshots[1]);
}

test('a full /vote is two Livewire components however many cards it shows', function (bool $moderator) {
    $html = fullVotePage($moderator)->getContent();

    // 50 questions, each in Top Suggestions and New Ideas.
    expect(substr_count($html, 'data-vote-count="'))->toBe(100);

    // The page and the topic component; no card is a component.
    $components = livewireComponents($html);
    expect($components)->toHaveCount(2)
        ->and(array_sum(array_map('strlen', $components)))->toBeLessThan(4096);
})->with(['viewer' => false, 'moderator' => true]);

test('the repeated icons are drawn from one sprite, defined once', function () {
    $html = fullVotePage(moderator: true)->getContent();

    foreach (['icon-thumb-up', 'icon-thumb-down', 'icon-trash'] as $icon) {
        expect(substr_count($html, 'id="'.$icon.'"'))->toBe(1, "{$icon} is defined once");
    }
    expect(substr_count($html, 'href="#icon-thumb-up"'))->toBe(100)
        ->and(substr_count($html, 'href="#icon-thumb-down"'))->toBe(100)
        ->and(substr_count($html, 'href="#icon-trash"'))->toBe(100, 'a moderator may delete every card');

    // No card carries its own icon paths, or a per-button loading spinner.
    $cards = substr($html, strpos($html, 'x-data="liveQueue"'));
    expect(substr_count($cards, '<path'))->toBe(0)
        ->and(substr_count($cards, 'animate-spin'))->toBe(0);
});

test('a card costs well under 3 KB of HTML (it was 8.8 KB)', function () {
    $empty = (function () {
        Topic::set('Songs about kale');

        return strlen(test()->actingAs(User::factory()->twitch('41')->create())->get('/vote')->getContent());
    })();
    $html = fullVotePage()->getContent();
    expect(substr_count($html, 'data-vote-count="'))->toBe(100);

    expect((strlen($html) - $empty) / 100)->toBeLessThan(3 * 1024);
});

test('each card is keyed by its vote_version, so a changed total renders afresh', function () {
    $question = Question::factory()->create();
    $question->recordVote(User::factory()->create(), 1);
    $html = $this->actingAs(User::factory()->create())->get('/vote')->getContent();

    expect($html)->toContain('wire:key="hot-li-'.$question->id.'-v1"')
        ->toContain('wire:key="recent-li-'.$question->id.'-v1"')
        ->toContain('data-vote-count="'.$question->id.'" data-vote-version="1"');
});
