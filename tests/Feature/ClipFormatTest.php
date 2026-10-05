<?php

use App\Clips\ClipHelix;
use App\Clips\ClipReview;
use App\Clips\ClipReviewStatus;
use App\Clips\ClipStorage;
use App\Clips\Ffmpeg;
use App\Jobs\Clips\FetchClipFile;
use App\Jobs\Clips\FormatClipForShorts;
use App\Models\StreamMarker;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Readiness\ReadinessChecks;
use App\Readiness\Status;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// #146: the 1080x1920 Shorts cut of approved clips, made with ffmpeg through
// Process. No test runs a real ffmpeg.

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    config(['clips.ffmpeg_binary' => '/opt/ffmpeg/ffmpeg', 'clips.ffmpeg_timeout' => 300, 'clips.vertical_mode' => 'crop']);
});

/** An approved clip with a stored landscape file (and a portrait one if asked). */
function approvedClip(array $attributes = [], bool $portrait = false): StreamMarker
{
    $marker = StreamMarker::factory()->ready()->create($attributes + [
        'clip_id' => 'ClipSlug',
        'review_status' => ClipReviewStatus::Approved,
        'reviewed_at' => now(),
        'fetched_at' => now(),
        'file_bytes' => 100,
        'clip_duration_seconds' => 60,
    ]);

    Storage::disk('local')->put("clips/{$marker->id}/ClipSlug-landscape.mp4", 'landscape-bytes');
    $marker->update(['landscape_file_path' => "clips/{$marker->id}/ClipSlug-landscape.mp4"]);
    if ($portrait) {
        Storage::disk('local')->put("clips/{$marker->id}/ClipSlug-portrait.mp4", 'portrait-bytes');
        $marker->update(['portrait_file_path' => "clips/{$marker->id}/ClipSlug-portrait.mp4"]);
    }

    return $marker->refresh();
}

/** Fake ffmpeg: write $bytes to the output path (the last argument), as the real one would. */
function fakeFfmpeg(string $bytes = 'vertical-mp4', int $exitCode = 0, string $stderr = ''): void
{
    Process::fake([
        '*' => function (PendingProcess $process) use ($bytes, $exitCode, $stderr) {
            if ($exitCode === 0) {
                file_put_contents(end($process->command), $bytes);
            }

            return Process::result(output: '', errorOutput: $stderr, exitCode: $exitCode);
        },
    ]);
}

function format(StreamMarker $marker): void
{
    (new FormatClipForShorts($marker->id))->handle();
}

/** The argument after $flag in the command ffmpeg was given. */
function argAfter(array $command, string $flag): ?string
{
    $i = array_search($flag, $command, true);

    return $i === false ? null : $command[$i + 1];
}

// --- the ffmpeg command ----------------------------------------------------------

test('a landscape clip is centre-cropped to 1080x1920 H.264/AAC, with the trim applied', function () {
    fakeFfmpeg();
    $marker = approvedClip(['trim_start_seconds' => 12.3, 'trim_end_seconds' => 47.0]);

    format($marker);

    Process::assertRan(function (PendingProcess $process) use ($marker) {
        $c = $process->command;

        return $c[0] === '/opt/ffmpeg/ffmpeg'
            && argAfter($c, '-ss') === '12.3'
            && argAfter($c, '-i') === Storage::disk('local')->path("clips/{$marker->id}/ClipSlug-landscape.mp4")
            && argAfter($c, '-t') === '34.7'
            && array_search('-ss', $c, true) < array_search('-i', $c, true)
            && argAfter($c, '-vf') === 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1'
            && argAfter($c, '-c:v') === 'libx264'
            && argAfter($c, '-pix_fmt') === 'yuv420p'
            && argAfter($c, '-c:a') === 'aac'
            && argAfter($c, '-movflags') === '+faststart'
            && in_array('0:a?', $c, true)
            && $process->timeout === 300;
    });
});

test('Twitch\'s portrait file is used when there is one, scaled and padded to exactly 1080x1920', function () {
    fakeFfmpeg();
    $marker = approvedClip(portrait: true);

    format($marker);

    Process::assertRan(fn (PendingProcess $p) => str_ends_with(argAfter($p->command, '-i'), 'ClipSlug-portrait.mp4')
        && argAfter($p->command, '-vf') === 'scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,setsar=1');
});

test('blur mode puts the whole frame over a blurred copy', function () {
    config(['clips.vertical_mode' => 'blur']);
    fakeFfmpeg();

    format(approvedClip());

    Process::assertRan(function (PendingProcess $p) {
        $graph = argAfter($p->command, '-filter_complex');

        return $graph !== null
            && str_contains($graph, 'boxblur')
            && str_contains($graph, 'overlay=(W-w)/2:(H-h)/2')
            && argAfter($p->command, '-map') === '[v]'
            && ! in_array('-vf', $p->command, true);
    });
});

test('an unknown mode falls back to crop', function () {
    config(['clips.vertical_mode' => 'stretch']);
    fakeFfmpeg();

    format(approvedClip());

    Process::assertRan(fn (PendingProcess $p) => str_contains((string) argAfter($p->command, '-vf'), 'crop=1080:1920'));
});

test('without a trim the whole clip is used', function () {
    fakeFfmpeg();

    format(approvedClip());

    Process::assertRan(fn (PendingProcess $p) => ! in_array('-ss', $p->command, true) && ! in_array('-t', $p->command, true));
});

test('the command is an argument list, never a shell string', function () {
    $args = FormatClipForShorts::ffmpegArguments('ffmpeg', '/tmp/in; rm -rf /.mp4', '/tmp/out.mp4', 'crop', 1, 10);

    expect($args)->toBeList()
        ->and($args)->toContain('/tmp/in; rm -rf /.mp4')
        ->and(end($args))->toBe('/tmp/out.mp4');
});

// --- storing the cut ---------------------------------------------------------------

test('the cut is stored next to the clip, with its size and signature', function () {
    fakeFfmpeg('vertical-mp4');
    $marker = approvedClip();

    format($marker);

    $marker->refresh();
    expect($marker->short_file_path)->toStartWith("clips/{$marker->id}/ClipSlug-short-")
        ->and($marker->short_bytes)->toBe(strlen('vertical-mp4'))
        ->and($marker->short_signature)->toHaveLength(64)
        ->and($marker->formatted_at)->not->toBeNull()
        ->and($marker->format_error)->toBeNull();
    expect(Storage::disk('local')->get($marker->short_file_path))->toBe('vertical-mp4');
});

test('formatting is idempotent: an up-to-date cut is not made again', function () {
    fakeFfmpeg();
    $marker = approvedClip();

    format($marker);
    format($marker);
    FormatClipForShorts::dispatch($marker->id);

    Process::assertRanTimes(fn () => true, 1);
});

test('a new trim makes a new cut and deletes the old one', function () {
    fakeFfmpeg();
    $marker = approvedClip();
    $mod = User::factory()->twitch('7777')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '7777']);

    format($marker);
    $first = $marker->refresh()->short_file_path;

    ClipReview::edit($marker, $mod, 'Better', 5, 40);
    ClipReview::approve($marker->refresh(), $mod);   // dispatches the job (sync queue)

    $marker->refresh();
    expect($marker->short_file_path)->not->toBe($first)
        ->and($marker->short_signature)->not->toBeNull();
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($marker->short_file_path);
    Process::assertRanTimes(fn () => true, 2);
});

test('a clip rejected while ffmpeg ran does not get its cut stored', function () {
    $marker = approvedClip();
    Process::fake([
        '*' => function (PendingProcess $process) use ($marker) {
            file_put_contents(end($process->command), 'late');
            StreamMarker::whereKey($marker->id)->update(['review_status' => ClipReviewStatus::Rejected->value]);

            return Process::result();
        },
    ]);

    format($marker);

    expect($marker->refresh()->short_file_path)->toBeNull();
    expect(Storage::disk('local')->allFiles("clips/{$marker->id}"))->each->not->toContain('short');
});

test('only approved, ready, unpruned clips with a stored file are formatted', function (array $attributes) {
    fakeFfmpeg();
    $marker = approvedClip();
    $marker->update($attributes);

    format($marker);

    Process::assertNothingRan();
    expect($marker->refresh()->short_file_path)->toBeNull();
})->with([
    'pending' => [['review_status' => ClipReviewStatus::Pending]],
    'rejected' => [['review_status' => ClipReviewStatus::Rejected]],
    'pruned' => [['files_pruned_at' => now()]],
    'not fetched' => [['landscape_file_path' => null]],
]);

// --- failures ----------------------------------------------------------------------

test('an ffmpeg failure is recorded on the clip and retried', function () {
    fakeFfmpeg(exitCode: 1, stderr: "Unknown encoder 'libx264'\nmore detail");
    $marker = approvedClip();

    expect(fn () => format($marker))->toThrow(RuntimeException::class, 'ffmpeg failed');

    expect($marker->refresh()->format_error)->toBe("ffmpeg failed (exit 1): Unknown encoder 'libx264'")
        ->and($marker->short_file_path)->toBeNull();
});

test('an ffmpeg timeout is recorded and rethrown', function () {
    Process::fake(['*' => fn () => throw new ProcessTimedOutException(
        new Symfony\Component\Process\Exception\ProcessTimedOutException(new Symfony\Component\Process\Process(['ffmpeg']), 1),
        Process::result(),
    )]);
    $marker = approvedClip();

    expect(fn () => format($marker))->toThrow(ProcessTimedOutException::class);

    expect($marker->refresh()->format_error)->toContain('longer than 300 s');
});

test('ffmpeg exiting 0 without output is a failure', function () {
    Process::fake(['*' => Process::result()]);
    $marker = approvedClip();

    expect(fn () => format($marker))->toThrow(RuntimeException::class, 'no output');
    expect($marker->refresh()->format_error)->toContain('without writing');
});

test('when formatting finally gives up, the clip says so', function () {
    $marker = approvedClip();

    (new FormatClipForShorts($marker->id))->failed(new RuntimeException('x'));

    expect($marker->refresh()->format_error)->toContain('Gave up formatting');
});

// --- dispatch and queue lane -------------------------------------------------------

test('approving a clip queues its Shorts cut on the clips queue', function () {
    Queue::fake([FormatClipForShorts::class]);
    config(['clips.format_connection' => 'redis-long']);
    $mod = User::factory()->twitch('7777')->create();
    $marker = approvedClip(['review_status' => ClipReviewStatus::Pending]);

    ClipReview::approve($marker, $mod);

    Queue::assertPushedOn('clips', FormatClipForShorts::class, fn (FormatClipForShorts $job) => $job->markerId === $marker->id
        && $job->connection === 'redis-long'
        && $job->timeout === 360);
});

test('a clip approved before its file arrived is formatted once the fetch finishes', function () {
    Queue::fake([FormatClipForShorts::class]);
    Http::fake(['production.assets.clips.twitchcdn.net/*' => Http::response('mp4', 200, ['Content-Type' => 'video/mp4'])]);
    $marker = StreamMarker::factory()->ready()->create([
        'review_status' => ClipReviewStatus::Approved,
        'reviewed_at' => now(),
        'download_urls_expire_at' => now()->addMinutes(20),
    ]);

    (new FetchClipFile($marker->id))->withFakeQueueInteractions()->handle(app(ClipHelix::class));

    Queue::assertPushed(FormatClipForShorts::class, fn ($job) => $job->markerId === $marker->id);
});

test('a fetched clip still to review is not formatted', function () {
    Queue::fake([FormatClipForShorts::class]);
    Http::fake(['production.assets.clips.twitchcdn.net/*' => Http::response('mp4', 200, ['Content-Type' => 'video/mp4'])]);
    $marker = StreamMarker::factory()->ready()->create(['download_urls_expire_at' => now()->addMinutes(20)]);

    (new FetchClipFile($marker->id))->withFakeQueueInteractions()->handle(app(ClipHelix::class));

    Queue::assertNotPushed(FormatClipForShorts::class);
});

test('supervisor-clips times out before redis-long retries, and after the job itself', function () {
    $supervisor = config('horizon.defaults.supervisor-clips');

    expect($supervisor['connection'])->toBe('redis-long')
        ->and($supervisor['queue'])->toBe(['clips'])
        ->and($supervisor['timeout'])->toBeLessThan(config('queue.connections.redis-long.retry_after') - 5)
        ->and((new FormatClipForShorts(1))->timeout)->toBeLessThan($supervisor['timeout']);
});

// --- retention and storage ---------------------------------------------------------

test('the prune deletes the Shorts cut along with the clip\'s files', function () {
    fakeFfmpeg('vertical');
    $marker = approvedClip(['reviewed_at' => now()]);
    format($marker);
    $short = $marker->refresh()->short_file_path;
    $marker->update(['reviewed_at' => now()->subDays(31)]);

    $this->artisan('clips:prune-files')->assertSuccessful();

    $marker->refresh();
    expect($marker->files_pruned_at)->not->toBeNull()
        ->and($marker->short_file_path)->toBeNull()
        ->and($marker->short_bytes)->toBeNull();
    Storage::disk('local')->assertMissing($short);
});

test('storage figures count the Shorts cut', function () {
    fakeFfmpeg('12345678');
    format(approvedClip(['file_bytes' => 100]));

    expect(ClipStorage::usage()['bytes'])->toBe(108);
});

test('mods can play the Shorts cut, viewers cannot', function () {
    fakeFfmpeg();
    $marker = approvedClip();
    format($marker);
    $url = route('clips.file', ['marker' => $marker->id, 'variant' => 'short']);
    $mod = User::factory()->twitch('7777')->create();
    TwitchModerator::create(['broadcaster_id' => '1000', 'twitch_user_id' => '7777']);

    $this->actingAs(User::factory()->twitch('4145994')->create())->get($url)->assertForbidden();
    $this->actingAs($mod)->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->actingAs($mod)->get('/clips?show=approved')
        ->assertSee('Shorts cut (1080×1920)')
        ->assertSee($url, false)
        ->assertDontSee($marker->refresh()->short_file_path);
});

// --- readiness ---------------------------------------------------------------------

test('the readiness probe really encodes with libx264 and AAC, and is green when it works', function () {
    Process::fake(['*' => Process::result()]);

    $check = Ffmpeg::readinessCheck();

    expect($check->status)->toBe(Status::Ok)
        ->and($check->summary)->toContain('/opt/ffmpeg/ffmpeg encoded a test clip');
    Process::assertRan(fn (PendingProcess $p) => $p->command === Ffmpeg::probeArguments()
        && in_array('lavfi', $p->command, true)
        && argAfter($p->command, '-c:v') === 'libx264'
        && argAfter($p->command, '-c:a') === 'aac'
        && argAfter($p->command, '-f') === 'lavfi'
        && end($p->command) === '-'
        && $p->timeout === Ffmpeg::PROBE_TIMEOUT_SECONDS);
});

test('a headless snap failure turns the readiness line amber with the install fix', function () {
    Process::fake(['*' => Process::result(errorOutput: "Error: unable to open display \n", exitCode: 1)]);

    $check = Ffmpeg::readinessCheck();

    expect($check->status)->toBe(Status::Warn)
        ->and($check->status->color())->toBe('amber')
        ->and($check->summary)->toContain('failed the encode probe (exit 1): Error: unable to open display')
        ->and($check->fix)->toContain('CLIPS_FFMPEG_BINARY')
        ->and($check->fix)->toContain('non-snap');
});

test('a snap binary path is flagged without running it', function () {
    config(['clips.ffmpeg_binary' => '/snap/bin/ffmpeg']);
    Process::fake();

    expect(Ffmpeg::readinessCheck()->status)->toBe(Status::Warn);
    Process::assertNothingRan();
});

test('a missing binary is amber, not an error page', function () {
    Process::fake(['*' => fn () => throw new Symfony\Component\Process\Exception\RuntimeException('not found')]);

    $check = Ffmpeg::readinessCheck();

    expect($check->status)->toBe(Status::Warn)
        ->and($check->summary)->toContain('Could not run /opt/ffmpeg/ffmpeg');
});

test('the readiness page lists the ffmpeg line in the Clips group', function () {
    Process::fake(['*' => Process::result()]);
    config(['clips.disk_min_free_bytes' => 0]);

    $names = collect(app(ReadinessChecks::class)->all()['Clips'])->pluck('name')->all();

    expect($names)->toBe(['Clip file storage', 'ffmpeg for Shorts formatting']);
});
