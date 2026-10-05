<?php

use App\Identities;
use App\IdentityProvider;
use App\Models\Question;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Moderation;
use App\Support\RequestMemo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config(['services.twitch.broadcaster_id' => '1000', 'services.twitch.broadcaster_ids' => []]);
});

/**
 * Sign $user in through the session, as a browser is, so the request loads
 * the user itself (actingAs() would hand it over and hide that query).
 */
function signInBySession(User $user): void
{
    test()->withSession([Auth::guard('web')->getName() => $user->id]);
}

/** @return Collection<int, string> the SQL of every query one GET /vote runs */
function voteQueries(): Collection
{
    Once::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get('/vote')->assertOk();
    DB::disableQueryLog();

    return collect(DB::getQueryLog())->pluck('query');
}

function voteFixture(bool $moderator): User
{
    $viewer = User::factory()->twitch('42')->create();
    if ($moderator) {
        TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
    }
    Topic::set('Songs about kale');
    Question::factory()->count(50)->for(User::factory())->create();

    return $viewer;
}

// #173: 24 queries on production, 18 of them the same two ban lookups.

test('a signed-in /vote with 50 questions makes at most 8 queries', function (bool $moderator) {
    $viewer = voteFixture($moderator);
    Question::cachedQueue();   // as on production, where the queue is served from the cache
    signInBySession($viewer);

    $queries = voteQueries();

    expect($queries->count())->toBeLessThanOrEqual(8)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'user_bans'))->count())->toBe(1)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'twitch_bans'))->count())->toBe(1)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'from "topics"'))->count())->toBe(1);
})->with(['viewer' => false, 'moderator' => true]);

test('a /vote that rebuilds the queue runs no query twice', function (bool $moderator) {
    signInBySession(voteFixture($moderator));
    Question::forgetCachedQueue();

    $queries = voteQueries();

    expect($queries->duplicates()->all())->toBe([])
        ->and($queries->count())->toBeLessThanOrEqual(11);
})->with(['viewer' => false, 'moderator' => true]);

test('the ban answer stays correct for a banned viewer, with the same single lookup', function () {
    $viewer = voteFixture(false);
    TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
    Question::cachedQueue();
    signInBySession($viewer);

    Once::flush();
    DB::enableQueryLog();
    $this->get('/vote')->assertRedirect('/?banned=1');
    DB::disableQueryLog();

    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'twitch_bans'))->count())->toBe(1);
    $this->assertGuest();
});

// The memo, inside one request

function insideARequest(Closure $run): void
{
    $memo = app(RequestMemo::class);
    $memo->enable();

    try {
        $run($memo);
    } finally {
        $memo->reset();
    }
}

test('inside a request the ban answer is asked once, and every ban change is seen at once', function () {
    $mod = User::factory()->twitch('77')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);
    $user = User::factory()->twitch('42')->create();

    insideARequest(function () use ($mod, $user) {
        DB::enableQueryLog();
        foreach (range(1, 10) as $_) {
            $user->isBanned();
        }
        expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'user_bans'))->count())->toBe(1);
        DB::disableQueryLog();

        // A local ban and its lifting (unban writes through the query builder).
        Moderation::ban($mod, $user, null);
        expect($user->isBanned())->toBeTrue()->and($user->isLocallyBanned())->toBeTrue();
        Moderation::unban($mod, $user);
        expect($user->isBanned())->toBeFalse();

        // A Twitch ban arriving, and leaving through the query builder as the webhook does.
        TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '42']);
        expect($user->isTwitchBanned())->toBeTrue();
        TwitchBan::query()->delete();
        RequestMemo::forgetBans();
        expect($user->isTwitchBanned())->toBeFalse();
    });
});

test('inside a request, lifting one ban and linking a banned account are seen at once', function () {
    $mod = User::factory()->twitch('77')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '77']);
    $user = User::factory()->facebook('fb-1')->create();
    $troll = User::factory()->twitch('99')->create();
    $ban = Moderation::ban($mod, $troll, null);
    $troll->delete();

    insideARequest(function () use ($mod, $user, $ban) {
        expect($user->isBanned())->toBeFalse();

        // Linking the banned Twitch account brings its ban with it.
        Identities::link($user, IdentityProvider::Twitch, (new SocialiteUser)->map(['id' => '99', 'name' => 'x', 'nickname' => 'x', 'email' => null, 'avatar' => null]));
        expect($user->isBanned())->toBeTrue();

        Moderation::liftBan($mod, $ban->fresh());
        expect($user->isBanned())->toBeFalse();
    });
});

test('inside a request the topic is read once, and a new topic is seen at once', function () {
    Topic::set('First');

    insideARequest(function () {
        DB::enableQueryLog();
        Topic::current();
        Topic::current();
        expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "topics"'))->count())->toBe(1);
        DB::disableQueryLog();

        Topic::set('Second');
        expect(Topic::current()->topic)->toBe('Second');

        Topic::archiveAll();
        expect(Topic::current())->toBeNull();
    });
});

test('outside a web request nothing is memoised', function () {
    $user = User::factory()->twitch('42')->create();
    expect(app(RequestMemo::class)->enabled())->toBeFalse();

    DB::enableQueryLog();
    $user->isBanned();
    $user->isBanned();
    DB::disableQueryLog();

    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'user_bans'))->count())->toBe(2);
});

test('the memo does not outlive its request', function () {
    $user = User::factory()->twitch('42')->create();
    signInBySession($user);
    $this->get('/vote')->assertOk();

    expect(app(RequestMemo::class)->enabled())->toBeFalse();

    // A ban placed between two requests is seen by the second.
    TwitchBan::query()->insert(['broadcaster_id' => '1000', 'twitch_user_id' => '42', 'created_at' => now(), 'updated_at' => now()]);
    $this->get('/vote')->assertRedirect('/?banned=1');
});
