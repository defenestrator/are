<?php

use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runtimeTableRepair(): Migration
{
    return require database_path('migrations/2026_10_04_000005_repair_missing_runtime_tables.php');
}

test('an empty database can migrate before cache clearing and repeat deployment preparation', function () {
    Schema::dropAllTables();
    config(['cache.default' => 'database']);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $migrationCount = DB::table('migrations')->count();
    expect(DB::table('migrations')->where('migration', '2026_10_04_000005_repair_missing_runtime_tables')->exists())->toBeTrue();
    $this->artisan('cache:clear')->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->artisan('cache:clear')->assertSuccessful();

    expect(DB::table('migrations')->count())->toBe($migrationCount)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('question_votes'))->toBeTrue();
});

test('the forward repair recreates a missing runtime table even with its original migration recorded', function (string $table, array $columns) {
    expect(DB::table('migrations')->count())->toBeGreaterThan(0);
    Schema::drop($table);

    $repair = runtimeTableRepair();
    $repair->up();
    $repair->up();

    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'sessions' => ['sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']],
    'cache' => ['cache', ['key', 'value', 'expiration']],
    'locks' => ['cache_locks', ['key', 'owner', 'expiration']],
    'jobs' => ['jobs', ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']],
    'batches' => ['job_batches', ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at']],
    'failed jobs' => ['failed_jobs', ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']],
]);

test('repair retries and rollback preserve runtime data and the question queue', function () {
    $user = User::factory()->create();
    $question = Question::factory()->for($user)->create();
    DB::table('question_votes')->insert(['user_id' => $user->id, 'question_id' => $question->id, 'count' => 1]);
    DB::table('sessions')->insert(['id' => 'keep-session', 'user_id' => $user->id, 'payload' => 'keep', 'last_activity' => time()]);
    DB::table('cache_locks')->insert(['key' => 'keep-lock', 'owner' => 'keep-owner', 'expiration' => time() + 60]);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'keep-job', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    DB::table('job_batches')->insert(['id' => 'keep-batch', 'name' => 'keep', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => time()]);
    DB::table('failed_jobs')->insert(['uuid' => 'keep-failed', 'connection' => 'database', 'queue' => 'default', 'payload' => 'keep', 'exception' => 'keep']);
    Cache::store('database')->put('keep-cache', 'keep-value', 60);

    $repair = runtimeTableRepair();
    $repair->up();
    $repair->up();
    $repair->down();

    expect(Cache::store('database')->get('keep-cache'))->toBe('keep-value')
        ->and(DB::table('sessions')->value('payload'))->toBe('keep')
        ->and(DB::table('cache_locks')->value('owner'))->toBe('keep-owner')
        ->and(DB::table('jobs')->value('payload'))->toBe('keep-job')
        ->and(DB::table('job_batches')->value('id'))->toBe('keep-batch')
        ->and(DB::table('failed_jobs')->value('uuid'))->toBe('keep-failed')
        ->and(User::find($user->id))->not->toBeNull()
        ->and($question->fresh()->voteCount())->toBe(1);
});

test('cache repair preserves existing cache while creating missing locks', function () {
    Cache::store('database')->put('keep-cache', 'keep-value', 60);
    Schema::drop('cache_locks');

    $migration = runtimeTableRepair();
    $migration->up();
    $migration->up();

    expect(Cache::store('database')->get('keep-cache'))->toBe('keep-value')
        ->and(Cache::store('database')->lock('repaired-lock', 10)->get())->toBeTrue();
});

test('queue repair creates missing failure storage without deleting existing jobs', function () {
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'keep-job', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    Schema::drop('failed_jobs');

    $migration = runtimeTableRepair();
    $migration->up();
    $migration->up();

    expect(DB::table('jobs')->value('payload'))->toBe('keep-job')
        ->and(Schema::hasTable('failed_jobs'))->toBeTrue();
});

test('repair restores database cache operations before deployment clears the cache', function () {
    Schema::drop('cache');
    Schema::drop('cache_locks');
    runtimeTableRepair()->up();
    config(['cache.default' => 'database']);

    Cache::put('deployment-probe', 'ready', 60);
    expect(Cache::get('deployment-probe'))->toBe('ready');
    $this->artisan('cache:clear')->assertSuccessful();
    expect(Cache::get('deployment-probe'))->toBeNull();
});
