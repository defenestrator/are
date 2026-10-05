<?php

use App\Clips\ClipHelix;
use App\Clips\ClipReview;
use App\Clips\ClipReviewStatus;
use App\Clips\ClipStorage;
use App\Jobs\Clips\FetchClipFile;
use App\Models\StreamMarker;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Readiness\ReadinessChecks;
use App\Readiness\Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// #143: clips:prune-files deletes the files of decided clips past their
// retention (7 days rejected, 30 days approved and unpublished by default).

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Process::preventStrayProcesses(); // approving queues ffmpeg (#146); never run it for real
    $this->freezeTime();
});

/** A ready clip with a stored landscape file, decided $daysAgo days ago. */
function storedClip(ClipReviewStatus $review, ?int $daysAgo, array $attributes = []): StreamMarker
{
    $marker = StreamMarker::factory()->ready()->create($attributes + [
        'review_status' => $review,
        'reviewed_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
        'fetched_at' => now()->subDays(40),
        'file_bytes' => 1000,
    ]);

    $path = 'clips/'.$marker->id.'/clip-landscape.mp4';
    Storage::disk('local')->put($path, str_repeat('x', 1000));
    $marker->update(['landscape_file_path' => $path]);

    return $marker;
}

function prune(array $options = []): void
{
    test()->artisan('clips:prune-files', $options)->assertSuccessful();
}

function filesGone(StreamMarker $marker): bool
{
    $marker->refresh();

    return $marker->files_pruned_at !== null && $marker->landscape_file_path === null && $marker->file_bytes === null;
}

// --- what gets pruned ----------------------------------------------------------

test('a rejected clip\'s files go 7 days after the decision, not before', function () {
    $old = storedClip(ClipReviewStatus::Rejected, 7);
    $recent = storedClip(ClipReviewStatus::Rejected, 6);
    $oldPath = $old->landscape_file_path;

    prune();

    expect(filesGone($old))->toBeTrue()
        ->and(filesGone($recent))->toBeFalse();
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($recent->landscape_file_path);
});

test('an approved clip not yet published keeps its files for 30 days', function () {
    $old = storedClip(ClipReviewStatus::Approved, 30);
    $recent = storedClip(ClipReviewStatus::Approved, 29);

    prune();

    expect(filesGone($old))->toBeTrue()
        ->and(filesGone($recent))->toBeFalse();
});

test('a published approved clip is not pruned by this rule', function () {
    $published = storedClip(ClipReviewStatus::Approved, 90, ['published_at' => now()->subDays(80)]);

    prune();

    expect(filesGone($published))->toBeFalse();
    Storage::disk('local')->assertExists($published->landscape_file_path);
});

test('a clip still to review is never pruned, however old', function () {
    $neverDecided = storedClip(ClipReviewStatus::Pending, null, ['created_at' => now()->subYear()]);
    // Edited after approval: back to review, with an old reviewed_at.
    $editedBack = storedClip(ClipReviewStatus::Pending, 365);

    prune();

    expect(filesGone($neverDecided))->toBeFalse()
        ->and(filesGone($editedBack))->toBeFalse();
    Storage::disk('local')->assertExists($neverDecided->landscape_file_path);
    Storage::disk('local')->assertExists($editedBack->landscape_file_path);
});

test('editing an approved clip back to review protects it from the prune', function () {
    $marker = storedClip(ClipReviewStatus::Approved, 45, ['clip_duration_seconds' => 60]);
    $mod = User::factory()->twitch('7777')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '7777']);

    ClipReview::edit($marker, $mod, 'New title', 0, 30);
    prune();

    expect($marker->refresh()->review_status)->toBe(ClipReviewStatus::Pending)
        ->and(filesGone($marker))->toBeFalse();
});

test('custom retention days come from config', function () {
    config(['clips.keep_rejected_days' => 1, 'clips.keep_approved_days' => 2]);
    $rejected = storedClip(ClipReviewStatus::Rejected, 1);
    $approved = storedClip(ClipReviewStatus::Approved, 2);
    $approvedRecent = storedClip(ClipReviewStatus::Approved, 1);

    prune();

    expect(filesGone($rejected))->toBeTrue()
        ->and(filesGone($approved))->toBeTrue()
        ->and(filesGone($approvedRecent))->toBeFalse();
});

test('both variants are deleted, and the row and its decisions stay', function () {
    $marker = storedClip(ClipReviewStatus::Rejected, 10);
    Storage::disk('local')->put('clips/'.$marker->id.'/clip-portrait.mp4', 'p');
    $marker->update(['portrait_file_path' => 'clips/'.$marker->id.'/clip-portrait.mp4']);

    prune();

    $marker->refresh();
    expect(StreamMarker::find($marker->id))->not->toBeNull()
        ->and($marker->portrait_file_path)->toBeNull()
        ->and($marker->review_status)->toBe(ClipReviewStatus::Rejected)
        ->and($marker->clip_id)->not->toBeNull();
    Storage::disk('local')->assertMissing('clips/'.$marker->id.'/clip-portrait.mp4');
    Storage::disk('local')->assertMissing('clips/'.$marker->id.'/clip-landscape.mp4');
});

test('a file already missing from the disk is still marked gone', function () {
    $marker = storedClip(ClipReviewStatus::Rejected, 10);
    Storage::disk('local')->delete($marker->landscape_file_path);

    prune();

    expect(filesGone($marker))->toBeTrue();
});

// --- idempotency, dry run and races ----------------------------------------------

test('pruning twice deletes nothing more and changes nothing', function () {
    $marker = storedClip(ClipReviewStatus::Rejected, 10);
    prune();
    $prunedAt = $marker->refresh()->files_pruned_at;

    $this->travel(1)->day();
    $this->artisan('clips:prune-files')->expectsOutputToContain('from 0 clip(s)')->assertSuccessful();

    expect($marker->refresh()->files_pruned_at->equalTo($prunedAt))->toBeTrue();
});

test('--dry-run lists what would go and deletes nothing', function () {
    $marker = storedClip(ClipReviewStatus::Rejected, 10);

    $this->artisan('clips:prune-files', ['--dry-run' => true])
        ->expectsOutputToContain("Would delete clip {$marker->id} (rejected")
        ->expectsOutputToContain('Would free 1.0 KB from 1 clip(s).')
        ->assertSuccessful();

    expect(filesGone($marker))->toBeFalse();
    Storage::disk('local')->assertExists($marker->landscape_file_path);
});

test('a decision that changes after the selection is respected', function () {
    $marker = storedClip(ClipReviewStatus::Rejected, 10);

    // Between the prune's query and its per-row lock, a mod sends the clip
    // back to review: simulate it on the first lock query.
    $flipped = false;
    DB::listen(function ($query) use ($marker, &$flipped) {
        if (! $flipped && str_contains(strtolower($query->sql), 'stream_markers') && str_contains(strtolower($query->sql), 'for update')) {
            $flipped = true;
            DB::table('stream_markers')->where('id', $marker->id)->update(['review_status' => ClipReviewStatus::Pending->value]);
        }
    });
    // SQLite has no FOR UPDATE, so flip on the re-check instead when the lock is a no-op.
    if (DB::connection()->getDriverName() === 'sqlite') {
        StreamMarker::retrieved(function (StreamMarker $m) use ($marker, &$flipped) {
            if (! $flipped && $m->id === $marker->id) {
                $flipped = true;
                DB::table('stream_markers')->where('id', $marker->id)->update(['review_status' => ClipReviewStatus::Pending->value]);
            }
        });
    }

    prune();

    expect($flipped)->toBeTrue()
        ->and(filesGone($marker))->toBeFalse();
    Storage::disk('local')->assertExists($marker->landscape_file_path);
});

test('a pruned clip is never fetched again', function () {
    Http::fake();
    $marker = storedClip(ClipReviewStatus::Rejected, 10);
    prune();

    (new FetchClipFile($marker->id))->withFakeQueueInteractions()->handle(app(ClipHelix::class));
    FetchClipFile::dispatch($marker->id);

    Http::assertNothingSent();
    expect(filesGone($marker))->toBeTrue();
});

test('the prune is scheduled daily without overlapping', function () {
    $event = collect(Schedule::events())->first(fn ($e) => str_contains((string) $e->command, 'clips:prune-files'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

// --- disk usage --------------------------------------------------------------------

test('usage counts only clips whose files are still stored', function () {
    storedClip(ClipReviewStatus::Pending, null);
    storedClip(ClipReviewStatus::Approved, 1, ['file_bytes' => 2500]);
    storedClip(ClipReviewStatus::Rejected, 10);
    prune();

    $usage = ClipStorage::usage();

    expect($usage['bytes'])->toBe(3500)
        ->and($usage['clips'])->toBe(2)
        ->and($usage['disk'])->toBe('local');
});

test('/clips shows the storage figure and the retention rules, and marks pruned files', function () {
    $mod = User::factory()->twitch('7777')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '7777']);
    storedClip(ClipReviewStatus::Pending, null, ['file_bytes' => 2048, 'description' => 'kept one']);
    storedClip(ClipReviewStatus::Rejected, 10, ['description' => 'pruned one']);
    prune();

    $this->actingAs($mod)->get('/clips?show=rejected')
        ->assertOk()
        ->assertSee('Clip files: 2.0 KB in 1 clip')
        ->assertSee('deleted after 7 days')
        ->assertSee('after 30')
        ->assertSee('pruned one')
        ->assertSee('File deleted');
});

test('the readiness page has a Clips line that is green under the thresholds', function () {
    config(['clips.disk_min_free_bytes' => 0]);
    storedClip(ClipReviewStatus::Pending, null);

    $check = app(ReadinessChecks::class)->all()['Clips'][0];

    expect($check->name)->toBe('Clip file storage')
        ->and($check->status)->toBe(Status::Ok)
        ->and($check->summary)->toContain('1 clip(s) on the local disk');
});

test('the readiness line turns amber when stored clips pass the threshold', function () {
    config(['clips.disk_warn_bytes' => 1500, 'clips.disk_min_free_bytes' => 0]);
    storedClip(ClipReviewStatus::Pending, null);
    storedClip(ClipReviewStatus::Pending, null);

    $check = ClipStorage::readinessCheck();

    expect($check->status)->toBe(Status::Warn)
        ->and($check->status->color())->toBe('amber')
        ->and($check->summary)->toContain('exceed CLIPS_DISK_WARN_BYTES')
        ->and($check->fix)->toContain('clips:prune-files --dry-run');
});

test('the readiness line turns amber when a local disk is low on free space', function () {
    config(['clips.disk_min_free_bytes' => PHP_INT_MAX]);

    $check = ClipStorage::readinessCheck();

    expect(ClipStorage::freeBytes())->toBeInt()
        ->and($check->status)->toBe(Status::Warn)
        ->and($check->summary)->toContain('below CLIPS_DISK_MIN_FREE_BYTES');
});

test('free space is not reported for a remote disk', function () {
    config(['filesystems.disks.remote-clips' => ['driver' => 's3', 'bucket' => 'x', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's'], 'clips.disk' => 'remote-clips']);

    expect(ClipStorage::freeBytes())->toBeNull()
        ->and(ClipStorage::readinessCheck()->status)->toBe(Status::Ok);
});
