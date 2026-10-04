<?php

use App\Models\ModerationAction;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserBan;
use App\Moderation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;
use Livewire\Volt\Volt;

function moderator(): User
{
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);

    return $mod;
}

/**
 * Test an anonymous @volt fragment that lives inside a full-page view.
 */
function fragment(string $name, string $view): Testable
{
    return Livewire::test(FragmentAlias::encode($name, resource_path("views/{$view}.blade.php")));
}

function vote(Question $question, User $user, int $count = 1): void
{
    DB::table('question_votes')->insert(['question_id' => $question->id, 'user_id' => $user->id, 'count' => $count]);
}

test('a local ban blocks a Facebook-only user and is logged', function () {
    $mod = moderator();
    $target = User::factory()->facebook('fb-9')->create(['name' => 'Facebook Troll']);

    Moderation::ban($mod, $target, null, 'spam');

    expect($target->isBanned())->toBeTrue()
        ->and($target->canSubmitQuestion())->toBeFalse()
        ->and(ModerationAction::where('action', 'user.banned')->first()->details)->toBe(['minutes' => null, 'reason' => 'spam']);
});

test('a timeout expires on its own', function () {
    $target = User::factory()->create();
    Moderation::ban(moderator(), $target, 10);

    expect($target->isBanned())->toBeTrue();

    $this->travel(11)->minutes();
    expect($target->isBanned())->toBeFalse();
});

test('lifting a ban unblocks the user and keeps the record', function () {
    $mod = moderator();
    $target = User::factory()->create();
    Moderation::ban($mod, $target, null);

    expect(Moderation::unban($mod, $target))->toBe(1)
        ->and($target->isBanned())->toBeFalse()
        ->and(UserBan::count())->toBe(1)
        ->and(UserBan::first()->lifted_at)->not->toBeNull();
});

test('viewers cannot ban, and nobody can ban a broadcaster or themselves', function () {
    $mod = moderator();

    expect(fn () => Moderation::ban(User::factory()->create(), User::factory()->create(), 10))->toThrow(AuthorizationException::class)
        ->and(fn () => Moderation::ban($mod, User::factory()->twitch('1000')->create(), 10))->toThrow(AuthorizationException::class, 'A broadcaster cannot be banned here.')
        ->and(fn () => Moderation::ban($mod, $mod, 10))->toThrow(AuthorizationException::class, 'You cannot ban yourself.')
        ->and(fn () => Moderation::ban($mod, User::factory()->create(), 0))->toThrow(InvalidArgumentException::class);

    expect(UserBan::count())->toBe(0);
});

test('a banned moderator loses moderator powers', function () {
    $mod = moderator();
    Moderation::ban(User::factory()->twitch('1000')->create(), $mod, null);

    expect(fn () => Moderation::ban($mod, User::factory()->create(), 10))->toThrow(AuthorizationException::class);
    $this->actingAs($mod)->get('/moderation')->assertRedirect('/?banned=1');
});

function moderatorQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    DB::disableQueryLog();

    return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'twitch_moderators'))->count();
}

test('a page with many moderate checks runs one moderator query', function (bool $isModerator) {
    $user = $isModerator ? moderator() : User::factory()->create();
    $this->actingAs($user);
    $template = str_repeat("@can('moderate') yes @endcan ", 10);

    $count = moderatorQueries(function () use ($template, $isModerator) {
        expect(substr_count(Blade::render($template), 'yes'))->toBe($isModerator ? 10 : 0);
    });

    expect($count)->toBe(1);
})->with(['viewer' => false, 'moderator' => true]);

test('the vote page runs one moderator query however many checks it renders', function () {
    $this->actingAs(moderator());
    Question::factory()->count(3)->create();

    expect(moderatorQueries(fn () => $this->get('/vote')->assertOk()))->toBe(1);
});

test('a ban that lands mid-request revokes moderate on the same user instance', function () {
    $mod = moderator();
    expect($mod->can('moderate'))->toBeTrue();

    Moderation::ban(User::factory()->twitch('1000')->create(), $mod, null);

    // The moderator lookup is memoised; the ban check is not.
    expect(moderatorQueries(fn () => expect($mod->can('moderate'))->toBeFalse()))->toBe(0);
});

test('merging moves votes to the kept question without double-counting', function () {
    $mod = moderator();
    [$a, $b, $c] = User::factory()->count(3)->create();
    $keep = Question::factory()->create(['question' => 'Sing about tea']);
    $dupe = Question::factory()->create(['question' => 'sing about TEA']);
    vote($keep, $a);
    vote($dupe, $a);      // already voted on the kept question: not moved
    vote($dupe, $b);      // moves
    vote($dupe, $c, -1);  // moves, downvotes too

    Moderation::mergeQuestions($mod, $dupe, $keep);

    expect(Question::find($dupe->id))->toBeNull()
        ->and($keep->voteCount())->toBe(1)
        ->and(DB::table('question_votes')->where('question_id', $keep->id)->count())->toBe(3)
        ->and(DB::table('question_votes')->where('user_id', $a->id)->count())->toBe(1)
        ->and(ModerationAction::where('action', 'question.merged')->first()->details)
        ->toMatchArray(['duplicate_id' => $dupe->id, 'votes_moved' => 2]);
});

test('a question cannot be merged into itself or an archived one', function () {
    $mod = moderator();
    $q = Question::factory()->create();
    $archived = Question::factory()->create(['archived_at' => now()]);

    expect(fn () => Moderation::mergeQuestions($mod, $q, $q))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Moderation::mergeQuestions($mod, $q, $archived))->toThrow(InvalidArgumentException::class);
});

test('a moderator deleting someone else\'s question is logged with its text; an author deleting their own is not', function () {
    $mod = moderator();
    $author = User::factory()->create();
    $theirs = Question::factory()->for($author)->create(['question' => 'Rude words']);
    $own = Question::factory()->for($author)->create();

    Moderation::deleteQuestion($mod, $theirs);
    Moderation::deleteQuestion($author, $own);

    expect(Question::count())->toBe(0)
        ->and(ModerationAction::count())->toBe(1)
        ->and(ModerationAction::first()->details['question'])->toBe('Rude words');
});

test('setting and clearing the topic are logged', function () {
    $mod = moderator();
    $this->actingAs($mod);

    Volt::test('topic')->set('topic', 'Songs about kale')->call('save')->call('clear');

    expect(ModerationAction::pluck('action')->all())->toBe(['topic.set', 'topic.cleared'])
        ->and(Topic::current())->toBeNull();
});

test('the moderation page is for moderators only', function () {
    $this->actingAs(User::factory()->create())->get('/moderation')->assertForbidden();

    Question::factory()->create(['question' => 'Queue item']);
    $this->actingAs(moderator())->get('/moderation')->assertOk()->assertSee('Queue item')->assertSee('Audit log');
});

test('the card shows a delete button to the author but not to other viewers', function () {
    $author = User::factory()->create();
    Question::factory()->for($author)->create();
    $question = Question::getSortedQuestions()->sole();

    $this->actingAs($author);
    expect(cardHtml($question))->toContain('aria-label="Delete question"');

    $this->actingAs(User::factory()->create());
    expect(cardHtml($question))->not->toContain('aria-label="Delete question"');
});

test('logging out requires POST', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/logout')->assertMethodNotAllowed();
    $this->assertAuthenticated();
    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});

test('banned users cannot submit questions, whether the ban is local or from Twitch', function (string $source) {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/vote')->assertOk();   // the page is open before the ban lands

    $source === 'local'
        ? Moderation::ban(moderator(), $user, 10)
        : TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => $user->twitch_id]);

    fragment('vote', 'vote')
        ->set('question', 'Sing about kale')
        ->call('saveQuestion')
        ->assertHasErrors(['question' => 'You are banned or timed out in this channel.']);

    expect(Question::count())->toBe(0);
})->with(['local', 'twitch']);

test('an unbanned user can submit through the same form', function () {
    $this->actingAs(User::factory()->create())->get('/vote')->assertOk();

    fragment('vote', 'vote')->set('question', 'Sing about kale')->call('saveQuestion')->assertHasNoErrors();

    expect(Question::count())->toBe(1);
});

test('a viewer calling deleteQuestion on someone else\'s card gets a 403', function () {
    $question = Question::factory()->create();
    $this->actingAs(User::factory()->create());

    onVotePage()->call('deleteQuestion', $question->id)->assertForbidden();

    expect(Question::find($question->id))->not->toBeNull();
});

test('a moderator deletes from the card, and a banned moderator cannot', function () {
    $mod = moderator();
    $fresh = function () {
        $id = Question::factory()->create()->id;

        return Question::getSortedQuestions()->firstWhere('id', $id);
    };
    $this->actingAs($mod);

    $first = $fresh();
    expect(cardHtml($first))->toContain('aria-label="Delete question"');
    onVotePage()->call('deleteQuestion', $first->id)->assertHasNoErrors();

    Moderation::ban(User::factory()->twitch('1000')->create(), $mod, null);
    $second = $fresh();
    expect(cardHtml($second))->not->toContain('aria-label="Delete question"');
    onVotePage()->call('deleteQuestion', $second->id)->assertForbidden();

    expect(Question::count())->toBe(1)
        ->and(ModerationAction::where('action', 'question.deleted')->count())->toBe(1);
});

test('a viewer calling the moderation page actions gets a 403', function () {
    $this->actingAs(moderator())->get('/moderation')->assertOk();   // registers the fragment

    $viewer = User::factory()->create();
    $victim = User::factory()->create();
    [$keep, $dupe] = Question::factory()->count(2)->create();
    $this->actingAs($viewer);

    fragment('moderation', 'moderation')->call('ban', $victim->id)->assertForbidden();
    fragment('moderation', 'moderation')->call('unban', $victim->id)->assertForbidden();
    fragment('moderation', 'moderation')
        ->set('duplicateId', $dupe->id)->set('targetId', $keep->id)
        ->call('merge')->assertForbidden();

    expect($victim->isBanned())->toBeFalse()
        ->and(Question::count())->toBe(2)
        ->and(ModerationAction::count())->toBe(0);
});

test('a moderator bans, merges and lifts from the page, and active bans are listed', function () {
    $mod = moderator();
    $target = User::factory()->create(['name' => 'Spammy McSpam']);
    [$keep, $dupe] = Question::factory()->count(2)->create();
    $this->actingAs($mod)->get('/moderation')->assertSee('Nobody is banned here.');

    fragment('moderation', 'moderation')
        ->set('duration', '60')->set('reason', 'link spam')->call('ban', $target->id)->assertHasNoErrors()
        ->set('duplicateId', $dupe->id)->set('targetId', $keep->id)->call('merge')->assertHasNoErrors();

    expect($target->isLocallyBanned())->toBeTrue()->and(Question::find($dupe->id))->toBeNull();

    $this->get('/moderation')->assertSee('Spammy McSpam')->assertSee('link spam')->assertDontSee('Nobody is banned here.');

    fragment('moderation', 'moderation')->call('unban', $target->id);

    expect($target->isBanned())->toBeFalse()
        ->and(ModerationAction::pluck('action')->all())->toBe(['user.banned', 'question.merged', 'user.unbanned']);
});

test('a moderator cannot ban themselves from the page', function () {
    $mod = moderator();
    $this->actingAs($mod)->get('/moderation')->assertOk();

    fragment('moderation', 'moderation')->call('ban', $mod->id)->assertForbidden();

    expect($mod->isBanned())->toBeFalse();
});

test('one moderator cannot ban another', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $a->twitch_id]);
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $b->twitch_id]);

    expect($a->can('ban', $b))->toBeFalse()
        ->and(fn () => Moderation::ban($a, $b, null))->toThrow(AuthorizationException::class, 'Only the broadcaster can ban a moderator.');

    expect($b->isBanned())->toBeFalse();
});

test('the broadcaster can ban a moderator', function () {
    $broadcaster = User::factory()->twitch('1000')->create();
    $mod = moderator();

    expect($broadcaster->can('ban', $mod))->toBeTrue();

    Moderation::ban($broadcaster, $mod, 60);
    expect($mod->isBanned())->toBeTrue();
});

test('the page offers no Ban button for a moderator, and calling ban anyway is a 403', function () {
    $a = moderator();
    $b = User::factory()->create(['name' => 'Fellow Mod']);
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $b->twitch_id]);
    $this->actingAs($a)->get('/moderation')->assertOk();

    fragment('moderation', 'moderation')
        ->set('search', 'Fellow')
        ->assertSee('Fellow Mod')
        ->assertDontSeeHtml('wire:click="ban('.$b->id.')"')
        ->call('ban', $b->id)
        ->assertForbidden();

    expect($b->isBanned())->toBeFalse();
});

test('a moderator cannot lift the broadcaster\'s ban on another moderator', function () {
    $broadcaster = User::factory()->twitch('1000')->create();
    $rogue = moderator();
    $other = moderator();
    Moderation::ban($broadcaster, $rogue, null, 'rogue');

    expect($other->can('unban', $rogue))->toBeFalse()
        ->and(fn () => Moderation::unban($other, $rogue))->toThrow(AuthorizationException::class, 'Only the broadcaster can lift a ban on a moderator.')
        ->and($rogue->isBanned())->toBeTrue()
        ->and($broadcaster->can('unban', $rogue))->toBeTrue();
});

test('the page hides Lift on a banned moderator from other moderators, and calling unban anyway is a 403', function () {
    $broadcaster = User::factory()->twitch('1000')->create();
    $rogue = User::factory()->create(['name' => 'Rogue Mod']);
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $rogue->twitch_id]);
    $viewer = User::factory()->create(['name' => 'Rogue Fan']);
    $rogueBan = Moderation::ban($broadcaster, $rogue, null);
    $viewerBan = Moderation::ban($broadcaster, $viewer, null);

    $this->actingAs(moderator())->get('/moderation')->assertOk();

    // The list of bans in effect lifts one ban at a time, by ban id.
    fragment('moderation', 'moderation')
        ->assertDontSeeHtml('wire:click="liftBan('.$rogueBan->id.')"')
        ->assertSeeHtml('wire:click="liftBan('.$viewerBan->id.')"')
        ->call('liftBan', $rogueBan->id)
        ->assertForbidden();

    fragment('moderation', 'moderation')
        ->set('search', 'Rogue')
        ->assertSee('Rogue Mod')
        ->assertDontSeeHtml('wire:click="unban('.$rogue->id.')"')
        ->assertSeeHtml('wire:click="unban('.$viewer->id.')"')
        ->call('unban', $rogue->id)
        ->assertForbidden();

    expect($rogue->isBanned())->toBeTrue();

    $this->actingAs($broadcaster);
    fragment('moderation', 'moderation')->assertSeeHtml('wire:click="liftBan('.$rogueBan->id.')"');
});

test('moderators can find a user whose name contains an underscore', function () {
    $mod = User::factory()->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => $mod->twitch_id]);
    User::factory()->create(['name' => 'Bob_Ross']);
    $this->actingAs($mod)->get('/moderation')->assertOk();   // registers the fragment

    Livewire::test(FragmentAlias::encode('moderation', resource_path('views/moderation.blade.php')))
        ->set('search', 'Bob_Ross')
        ->assertSeeHtml('wire:key="mu-');
});

test('name search treats LIKE wildcards and the escape character literally', function () {
    foreach (['Bob_Ross', 'BobXRoss', '100% Kale', '100 Kale', 'Bang!Bang', 'a\\b'] as $name) {
        User::factory()->create(['name' => $name]);
    }

    $find = fn (string $term) => User::whereNameContains($term)->orderBy('name')->pluck('name')->all();

    expect($find('Bob_Ross'))->toBe(['Bob_Ross'])
        ->and($find('100%'))->toBe(['100% Kale'])
        ->and($find('g!B'))->toBe(['Bang!Bang'])
        ->and($find('a\\b'))->toBe(['a\\b'])
        ->and($find('ross'))->toEqualCanonicalizing(['Bob_Ross', 'BobXRoss']);
});

test('name search ignores case', function () {
    User::factory()->create(['name' => 'Jeremy']);
    User::factory()->create(['name' => 'xX_TROLL_Xx']);

    expect(User::whereNameContains('jeremy')->pluck('name')->all())->toBe(['Jeremy'])
        ->and(User::whereNameContains('JEREMY')->pluck('name')->all())->toBe(['Jeremy'])
        ->and(User::whereNameContains('xx_troll_xx')->pluck('name')->all())->toBe(['xX_TROLL_Xx']);
});

test('name search compiles to a case-insensitive, !-escaped match on configured drivers', function (string $connection, string $sql) {
    // toSql() only needs the grammar, so no server is contacted.
    expect(User::on($connection)->whereNameContains('a_b')->toSql())->toContain($sql);
})->with([
    'pgsql uses ILIKE' => ['pgsql', '"users"."name" ilike ? escape \'!\''],
    'mysql uses LIKE (case-insensitive collation)' => ['mysql', '`users`.`name` like ? escape \'!\''],
]);
