<?php

use App\IdentityProvider;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two migrations that move platform ids from users to identities.
 *
 * @return array{0: \Illuminate\Database\Migrations\Migration, 1: \Illuminate\Database\Migrations\Migration}
 */
function identityMigrations(): array
{
    return [
        require database_path('migrations/2026_10_04_100000_create_identities_table.php'),
        require database_path('migrations/2026_10_04_100001_drop_platform_ids_from_users_table.php'),
    ];
}

/**
 * Put the schema back as it was before identities, then seed rows in that shape:
 * a Twitch user with a token, a Facebook user, a user with neither, and their
 * questions and votes.
 *
 * @return array<string, int>
 */
function seedPreIdentitySchema(): array
{
    [$create, $drop] = identityMigrations();
    $drop->down();
    $create->down();

    expect(Schema::hasTable('identities'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'twitch_id'))->toBeTrue();

    $now = now();
    $twitch = DB::table('users')->insertGetId([
        'name' => 'Twitch Viewer', 'twitch_id' => '42', 'twitch_avatar_url' => 'https://example.com/42.png',
        'twitch_token' => 'legacy-token', 'created_at' => $now->copy()->subYear(), 'updated_at' => $now,
    ]);
    $facebook = DB::table('users')->insertGetId([
        'name' => 'Face Person', 'email' => 'fb@example.com', 'facebook_id' => 'fb-7',
        'facebook_avatar_url' => 'https://example.com/fb.png', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $neither = DB::table('users')->insertGetId(['name' => 'Nobody', 'created_at' => $now, 'updated_at' => $now]);

    $question = DB::table('questions')->insertGetId(['user_id' => $twitch, 'question' => 'Sing about tea', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('question_votes')->insert([
        ['user_id' => $twitch, 'question_id' => $question, 'count' => 1],
        ['user_id' => $facebook, 'question_id' => $question, 'count' => -1],
    ]);

    return compact('twitch', 'facebook', 'neither', 'question');
}

test('the migration back-fills identities from users and keeps users, questions and votes', function () {
    $ids = seedPreIdentitySchema();
    $before = [
        'users' => DB::table('users')->orderBy('id')->get(['id', 'name', 'email'])->toArray(),
        'questions' => DB::table('questions')->get()->toArray(),
        'votes' => DB::table('question_votes')->get()->toArray(),
    ];

    foreach (identityMigrations() as $migration) {
        $migration->up();
    }

    expect(DB::table('users')->orderBy('id')->get(['id', 'name', 'email'])->toArray())->toEqual($before['users'])
        ->and(DB::table('questions')->get()->toArray())->toEqual($before['questions'])
        ->and(DB::table('question_votes')->get()->toArray())->toEqual($before['votes'])
        ->and(Schema::hasColumns('users', ['twitch_id', 'facebook_id', 'twitch_token']))->toBeFalse()
        ->and(Identity::count())->toBe(2);

    $twitch = User::find($ids['twitch']);
    $identity = $twitch->identityFor(IdentityProvider::Twitch);
    expect($twitch->twitch_id)->toBe('42')
        ->and($twitch->twitch_avatar_url)->toBe('https://example.com/42.png')
        ->and($identity->access_token)->toBe('legacy-token')
        ->and(DB::table('identities')->where('id', $identity->id)->value('access_token'))->not->toBe('legacy-token');

    $facebook = User::find($ids['facebook']);
    expect($facebook->facebook_id)->toBe('fb-7')
        ->and($facebook->facebook_avatar_url)->toBe('https://example.com/fb.png')
        ->and($facebook->identityFor(IdentityProvider::Facebook)->email)->toBe('fb@example.com')
        ->and($facebook->twitch_id)->toBeNull();

    expect(User::find($ids['neither'])->identities)->toBeEmpty()
        ->and(User::find($ids['twitch'])->votes()->count())->toBe(1);
});

test('the migration rolls back to the old columns with their values', function () {
    $ids = seedPreIdentitySchema();
    [$create, $drop] = identityMigrations();
    $create->up();
    $drop->up();

    $drop->down();
    $create->down();

    expect(Schema::hasTable('identities'))->toBeFalse()
        ->and(DB::table('users')->count())->toBe(3)
        ->and(DB::table('question_votes')->count())->toBe(2);

    $twitch = DB::table('users')->find($ids['twitch']);
    $facebook = DB::table('users')->find($ids['facebook']);
    expect($twitch->twitch_id)->toBe('42')
        ->and($twitch->twitch_token)->toBe('legacy-token')
        ->and($twitch->twitch_avatar_url)->toBe('https://example.com/42.png')
        ->and($facebook->facebook_id)->toBe('fb-7')
        ->and($facebook->facebook_avatar_url)->toBe('https://example.com/fb.png');

    // And forward again, so the database is left as the suite expects.
    $create->up();
    $drop->up();
    expect(Identity::count())->toBe(2);
});

test('rolling back restores Twitch and Facebook ids for users created after the migration', function () {
    $user = User::factory()->twitch('42')->facebook('fb-7')->create();
    Identity::where('provider', 'twitch')->update(['access_token' => Crypt::encryptString('fresh-token')]);
    [$create, $drop] = identityMigrations();

    $drop->down();

    $row = DB::table('users')->find($user->id);
    expect($row->twitch_id)->toBe('42')
        ->and($row->twitch_token)->toBe('fresh-token')
        ->and($row->facebook_id)->toBe('fb-7');

    $drop->up();
});
