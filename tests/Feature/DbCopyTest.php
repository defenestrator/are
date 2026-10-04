<?php

use App\Identities;
use App\IdentityProvider;
use App\Models\BroadcasterToken;
use App\Models\ModerationAction;
use App\Models\Question;
use App\Models\QuestionVote;
use App\Models\Topic;
use App\Models\TwitchBan;
use App\Models\User;
use App\Models\UserBan;
use App\Models\UserTwitchSubscription;
use App\TwitchSubscription;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The source is a real SQLite file, migrated and seeded through its own
// connection. The target is the test connection: in-memory SQLite in the
// `tests` job and PostgreSQL 14 in `tests-pgsql`.

const SEED = 'db_copy_seed';

beforeEach(function () {
    $this->sourcePath = tempnam(sys_get_temp_dir(), 'are-db-copy-');
    config(['database.connections.'.SEED => [
        'driver' => 'sqlite',
        'database' => $this->sourcePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    Artisan::call('migrate', ['--database' => SEED, '--force' => true]);
    $this->target = config('database.default');
});

afterEach(function () {
    DB::purge(SEED);
    DB::purge('db_copy_source');
    @unlink($this->sourcePath);
});

/** Runs $seed with the source as the default connection, so models and factories write there. */
function onSource(Closure $seed): mixed
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection(SEED);

    try {
        return $seed();
    } finally {
        DB::setDefaultConnection($default);
    }
}

/** A table no migration knows about, created on both sides, that sorts before the table it references. */
function createProbeTable(string $connection): void
{
    Schema::connection($connection)->create('a_probes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->boolean('is_live');
        $table->boolean('was_live')->nullable();
        $table->date('aired_on')->nullable();
        $table->timestamp('aired_at')->nullable();
    });
}

function seedSource(): array
{
    return onSource(function () {
        $users = User::factory()->count(6)->create();
        $facebookOnly = User::factory()->facebook('fb-42')->create(['name' => 'Facebook Viewer', 'email' => 'viewer@example.com']);
        // Leave a gap in users.id, so a sequence that only counted rows would hand out a taken id.
        $users[2]->delete();

        $questions = Question::factory()->count(5)->recycle($users->reject(fn (User $user) => $user->is($users[2])))->create();
        $questions[0]->forceFill(['archived_at' => '2026-10-01 18:30:00'])->save();
        foreach ([$users[0], $users[1], $facebookOnly] as $voter) {
            QuestionVote::create(['question_id' => $questions[1]->id, 'user_id' => $voter->id, 'count' => 2]);
        }

        Topic::create(['topic' => 'Fire safety for streamers']);
        TwitchBan::create(['broadcaster_id' => '1000', 'twitch_user_id' => '777', 'ends_at' => '2026-10-05 12:00:00']);
        BroadcasterToken::create([
            'broadcaster_id' => '1000',
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'expires_at' => now()->addHours(4),
            'scopes' => ['moderator:read:chatters', 'channel:manage:broadcast'],
        ]);
        UserBan::create(['user_id' => $users[4]->id, 'moderator_id' => $users[0]->id, 'reason' => 'spam']);
        ModerationAction::create(['moderator_id' => $users[0]->id, 'action' => 'ban', 'details' => ['reason' => 'spam', 'minutes' => 10]]);
        UserTwitchSubscription::create(['user_id' => $users[0]->id, 'broadcaster_id' => '1000', 'twitch_subscription' => TwitchSubscription::Tier2]);

        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $users[0]->id, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => time() + 60]);

        return ['maxUserId' => $facebookOnly->id, 'maxQuestionId' => $questions->max('id')];
    });
}

function copyCommand(array $options = []): array
{
    return $options + ['--from' => 'sqlite', '--to' => config('database.default'), '--source-path' => test()->sourcePath];
}

test('it copies every table, keeps the values and verifies the counts', function () {
    seedSource();

    // A chunk of 2 makes every table with more than two rows take several reads and inserts.
    $this->artisan('db:copy', copyCommand(['--chunk' => 2]))
        ->expectsOutputToContain('Every copied table has the same row count on both sides.')
        ->assertSuccessful();

    foreach (['users' => 6, 'questions' => 5, 'question_votes' => 3, 'topics' => 1, 'twitch_bans' => 1,
        'broadcaster_tokens' => 1, 'user_bans' => 1, 'moderation_actions' => 1, 'user_twitch_subscriptions' => 1,
        // Five Twitch identities (the deleted user's went with it) and one Facebook identity.
        'identities' => 6] as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, $table);
    }

    $source = DB::connection(SEED);
    expect(DB::table('users')->orderBy('id')->pluck('name', 'id')->all())
        ->toBe($source->table('users')->orderBy('id')->pluck('name', 'id')->all())
        ->and(Identities::findUser(IdentityProvider::Facebook, 'fb-42'))
        ->twitch_id->toBeNull()
        ->email->toBe('viewer@example.com')
        ->and(Question::orderBy('id')->first()->archived_at->toDateTimeString())->toBe('2026-10-01 18:30:00')
        ->and(Question::orderBy('id')->pluck('question')->all())->toBe($source->table('questions')->orderBy('id')->pluck('question')->all())
        ->and(TwitchBan::first()->ends_at->toDateTimeString())->toBe('2026-10-05 12:00:00')
        ->and(BroadcasterToken::first())
        ->access_token->toBe('access-secret')
        ->scopes->toBe(['moderator:read:chatters', 'channel:manage:broadcast'])
        ->and(ModerationAction::first()->details)->toBe(['reason' => 'spam', 'minutes' => 10])
        ->and(UserTwitchSubscription::first()->twitch_subscription)->toBe(TwitchSubscription::Tier2);
});

test('sessions, cache and jobs are skipped unless asked for', function () {
    seedSource();

    $this->artisan('db:copy', copyCommand())
        ->expectsOutputToContain('sessions (1 rows in source)')
        ->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(0)
        ->and(DB::table('cache')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(6);
});

test('passing an empty --skip copies the transient tables too', function () {
    seedSource();

    $this->artisan('db:copy', copyCommand(['--skip' => ['']]))->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and(DB::table('cache')->count())->toBe(1);
});

test('tables no migration knows about are found, ordered by foreign key, and their booleans and dates normalised', function () {
    createProbeTable(SEED);
    createProbeTable($this->target);
    $userId = onSource(fn () => User::factory()->create()->id);

    // Raw values the way SQLite can hold them: 0/1 and 't'/'f', ISO 8601 with an
    // offset, a Unix timestamp, an empty string and a datetime in a date column.
    DB::connection(SEED)->table('a_probes')->insert([
        ['user_id' => $userId, 'is_live' => 1, 'was_live' => 'f', 'aired_on' => '2026-10-04 00:00:00', 'aired_at' => '2026-10-04T20:15:00+02:00'],
        ['user_id' => $userId, 'is_live' => 0, 'was_live' => null, 'aired_on' => null, 'aired_at' => 1790000000],
        ['user_id' => $userId, 'is_live' => 't', 'was_live' => '1', 'aired_on' => '2026-10-05', 'aired_at' => ''],
    ]);

    $this->artisan('db:copy', copyCommand())->assertSuccessful();

    $rows = DB::table('a_probes')->orderBy('id')->get();
    $true = fn ($value) => in_array($value, [true, 1], true);
    $false = fn ($value) => in_array($value, [false, 0], true);

    expect($rows)->toHaveCount(3)
        ->and($true($rows[0]->is_live))->toBeTrue()
        ->and($false($rows[1]->is_live))->toBeTrue()
        ->and($true($rows[2]->is_live))->toBeTrue()
        ->and($false($rows[0]->was_live))->toBeTrue()
        ->and($rows[1]->was_live)->toBeNull()
        ->and($true($rows[2]->was_live))->toBeTrue()
        ->and($rows[0]->aired_on)->toBe('2026-10-04')
        ->and($rows[2]->aired_on)->toBe('2026-10-05')
        ->and($rows[0]->aired_at)->toBe('2026-10-04 18:15:00')
        ->and($rows[1]->aired_at)->toBe('2026-09-21 14:13:20')
        ->and($rows[2]->aired_at)->toBeNull();
});

test('after the copy the next insert gets the id after the highest copied one', function () {
    $max = seedSource();

    $this->artisan('db:copy', copyCommand())->assertSuccessful();

    expect(User::factory()->create()->id)->toBe($max['maxUserId'] + 1)
        ->and(Question::factory()->create()->id)->toBe($max['maxQuestionId'] + 1);
})->skip(
    fn () => DB::connection()->getDriverName() !== 'pgsql',
    'Sequence reset is PostgreSQL-only: SQLite derives the next rowid from MAX(id), so there is nothing to reset. The tests-pgsql CI job runs this.',
);

test('it refuses a target that already holds rows', function () {
    seedSource();
    Topic::create(['topic' => 'already here']);

    $this->artisan('db:copy', copyCommand())
        ->expectsOutputToContain('The target already holds rows in: topics')
        ->assertFailed();

    expect(DB::table('users')->count())->toBe(0);
});

test('it refuses values longer than a target varchar allows, before writing anything', function () {
    seedSource();
    onSource(fn () => User::factory()->create(['name' => str_repeat('x', 300)]));

    $this->artisan('db:copy', copyCommand())
        ->expectsOutputToContain('users.name (limit 255, longest 300)')
        ->assertFailed();

    expect(DB::table('users')->count())->toBe(0);
})->skip(
    fn () => DB::connection()->getDriverName() !== 'pgsql',
    'Laravel declares strings on SQLite as varchar with no length, so only a PostgreSQL target has a limit to check. The tests-pgsql CI job runs this.',
);

test('it refuses when the source and target are at different migrations', function () {
    seedSource();
    DB::connection(SEED)->table('migrations')->orderByDesc('id')->limit(1)->delete();

    $this->artisan('db:copy', copyCommand())
        ->expectsOutputToContain('The source and the target are at different migrations')
        ->assertFailed();

    expect(DB::table('users')->count())->toBe(0);
});

test('it refuses a missing source file', function () {
    $this->artisan('db:copy', copyCommand(['--source-path' => $this->sourcePath.'-missing']))
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

test('a dry run checks the guards, prints the plan and writes nothing', function () {
    seedSource();

    $this->artisan('db:copy', copyCommand(['--dry-run' => true]))
        ->expectsOutputToContain('Dry run: the guards passed and nothing was written.')
        ->assertSuccessful();

    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('questions')->count())->toBe(0);
});

test('a count mismatch fails the command', function () {
    seedSource();

    // A row that reaches the source after topics was copied, as a write to
    // the old database during the cutover would.
    $added = false;
    DB::listen(function (QueryExecuted $query) use (&$added) {
        if (! $added && $query->connectionName === config('database.default') && str_contains($query->sql, 'insert into "topics"')) {
            $added = true;
            DB::connection(SEED)->table('topics')->insert(['topic' => 'late', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->artisan('db:copy', copyCommand())
        ->expectsOutputToContain('1 table(s) have different row counts.')
        ->assertFailed();
});
