<?php

namespace App\Jobs\Clips;

use App\Clips\ClipReviewStatus;
use App\Clips\StreamMarkerStatus;
use App\Models\StreamMarker;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Make the 1080x1920 Shorts cut of an approved clip (#146).
 *
 * Applies the stored trim and turns the clip vertical: Twitch's portrait file
 * when there is one (scaled and padded to exactly 1080x1920), otherwise the
 * landscape file by clips.vertical_mode ("crop" centre, or "blur" for the
 * whole frame over a blurred copy). H.264 and AAC, faststart MP4.
 *
 * Idempotent: the cut is stored with a signature of its source, trim and
 * mode, and a run whose signature matches the stored cut does nothing. A new
 * trim or mode makes a new file and deletes the old one. If the clip is no
 * longer approved, or its trim changed, by the time ffmpeg finishes, the new
 * cut is thrown away rather than stored.
 *
 * Runs on the "clips" queue of a long-job connection (redis-long with
 * Horizon's supervisor-clips, or database-long with its own worker), because
 * an encode outlasts the default connections' 90 s retry_after. queueFor()
 * refuses to queue it on a lane that would hand it to a second worker.
 */
class FormatClipForShorts implements ShouldQueue
{
    use Queueable;

    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const MODES = ['crop', 'blur'];

    /** Bump to re-format every clip after changing the ffmpeg recipe. */
    public const RECIPE = 1;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60];

    public int $timeout;

    public function __construct(public int $markerId)
    {
        $this->timeout = (int) config('clips.ffmpeg_timeout') + 60;
        $this->onQueue('clips');
        $this->onConnection(self::connectionName());
    }

    /**
     * The long-job lane for the active queue backend: clips.format_connection
     * if set, else redis-long or database-long, else (sync in tests, or any
     * other driver) the default connection.
     */
    public static function connectionName(): string
    {
        $forced = config('clips.format_connection');
        if (is_string($forced) && $forced !== '') {
            return $forced;
        }

        $default = (string) config('queue.default');

        return match (config("queue.connections.{$default}.driver")) {
            'redis' => 'redis-long',
            'database' => 'database-long',
            default => $default,
        };
    }

    /**
     * Why the resolved connection cannot run this job safely, or null if it
     * can: its retry_after must exceed the job timeout, or a second worker
     * picks up an encode that is still running.
     */
    public static function unsafeConnection(): ?string
    {
        $connection = self::connectionName();
        $config = config("queue.connections.{$connection}");

        if (! is_array($config)) {
            return "the queue connection {$connection} does not exist";
        }
        if (($config['driver'] ?? null) === 'sync') {
            return null;
        }

        $timeout = (int) config('clips.ffmpeg_timeout') + 60;
        $retryAfter = (int) ($config['retry_after'] ?? 0);

        return $retryAfter > $timeout
            ? null
            : "the {$connection} connection's retry_after ({$retryAfter} s) is not above the job timeout ({$timeout} s)";
    }

    /**
     * Queue the Shorts cut, unless the lane would run it twice. Refusing is
     * logged and shown on the clip, so it cannot pass unnoticed. Use this,
     * never dispatch() directly.
     */
    public static function queueFor(StreamMarker $marker): bool
    {
        $problem = self::unsafeConnection();

        if ($problem !== null) {
            Log::error('Refused to queue a Shorts cut: '.$problem.'. Set DB_LONG_QUEUE_RETRY_AFTER or REDIS_LONG_QUEUE_RETRY_AFTER above CLIPS_FFMPEG_TIMEOUT + 60, or lower CLIPS_FFMPEG_TIMEOUT (#146).', [
                'marker_id' => $marker->id,
                'connection' => self::connectionName(),
            ]);
            $marker->update(['format_error' => 'Not queued: '.$problem.'. See the readiness page.']);

            return false;
        }

        self::dispatch($marker->id);

        return true;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('clip-format:'.$this->markerId))->releaseAfter(60)->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        $marker = StreamMarker::find($this->markerId);
        if ($marker === null || ! self::wanted($marker)) {
            return;
        }

        $disk = Storage::disk(config('clips.disk'));
        $source = self::source($marker);
        if ($source === null || ! $disk->exists($source['path'])) {
            // Not fetched yet: FetchClipFile queues this job again once it is.
            return;
        }

        $signature = self::signature($marker, $source);
        if ($marker->short_signature === $signature && $marker->short_file_path !== null && $disk->exists($marker->short_file_path)) {
            return;
        }

        [$input, $inputIsTemp] = $this->localInput($disk, $source['path']);
        $output = sys_get_temp_dir().'/clip-short-'.$marker->id.'-'.Str::random(8).'.mp4';

        try {
            $command = self::ffmpegArguments(
                (string) config('clips.ffmpeg_binary'),
                $input,
                $output,
                $source['mode'],
                $marker->trim_start_seconds,
                $marker->trim_end_seconds,
            );

            try {
                $result = Process::timeout((int) config('clips.ffmpeg_timeout'))->run($command);
            } catch (ProcessTimedOutException $e) {
                $marker->update(['format_error' => 'ffmpeg took longer than '.config('clips.ffmpeg_timeout').' s (CLIPS_FFMPEG_TIMEOUT).']);
                throw $e;
            }

            if ($result->failed()) {
                $marker->update(['format_error' => 'ffmpeg failed (exit '.$result->exitCode().'): '.self::firstLine($result->errorOutput())]);
                throw new RuntimeException('ffmpeg failed formatting clip '.$marker->id.'.');
            }

            if (! is_file($output) || filesize($output) === 0) {
                $marker->update(['format_error' => 'ffmpeg exited without writing the cut.']);
                throw new RuntimeException('ffmpeg wrote no output for clip '.$marker->id.'.');
            }

            // A mod may have rejected the clip, or changed its trim, mid-encode.
            $marker->refresh();
            if (! self::wanted($marker) || self::source($marker) === null || self::signature($marker, self::source($marker)) !== $signature) {
                return;
            }

            $this->store($marker, $disk, $output, $signature);
        } finally {
            @unlink($output);
            if ($inputIsTemp) {
                @unlink($input);
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        $marker = StreamMarker::find($this->markerId);
        if ($marker !== null && $marker->format_error === null) {
            $marker->update(['format_error' => 'Gave up formatting the clip'.($e !== null ? ' ('.class_basename($e).').' : '.')]);
        }
    }

    /** Approved, ready, not pruned: the only clips worth a Shorts cut. */
    public static function wanted(StreamMarker $marker): bool
    {
        return $marker->status === StreamMarkerStatus::ClipReady
            && $marker->review_status === ClipReviewStatus::Approved
            && $marker->files_pruned_at === null;
    }

    /**
     * The file to cut from, and how: Twitch's portrait file as is, otherwise
     * the landscape file by clips.vertical_mode.
     *
     * @return array{path: string, mode: string}|null
     */
    public static function source(StreamMarker $marker): ?array
    {
        if ($marker->portrait_file_path !== null) {
            return ['path' => $marker->portrait_file_path, 'mode' => 'portrait'];
        }

        if ($marker->landscape_file_path !== null) {
            $mode = (string) config('clips.vertical_mode');

            return ['path' => $marker->landscape_file_path, 'mode' => in_array($mode, self::MODES, true) ? $mode : 'crop'];
        }

        return null;
    }

    /**
     * @param  array{path: string, mode: string}  $source
     */
    public static function signature(StreamMarker $marker, array $source): string
    {
        return hash('sha256', json_encode([
            self::RECIPE,
            $source['path'],
            $source['mode'],
            $marker->trim_start_seconds,
            $marker->trim_end_seconds,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The ffmpeg command, as an argument list: no shell is involved, so no
     * path or value can be read as shell syntax.
     *
     * @return list<string>
     */
    public static function ffmpegArguments(string $binary, string $input, string $output, string $mode, ?float $trimStart, ?float $trimEnd): array
    {
        $args = [$binary, '-hide_banner', '-nostdin', '-y', '-loglevel', 'error'];

        $start = max(0.0, (float) $trimStart);
        if ($start > 0) {
            array_push($args, '-ss', self::seconds($start));
        }
        array_push($args, '-i', $input);
        if ($trimEnd !== null && $trimEnd > $start) {
            array_push($args, '-t', self::seconds($trimEnd - $start));
        }

        $w = self::WIDTH;
        $h = self::HEIGHT;

        if ($mode === 'blur') {
            array_push(
                $args,
                '-filter_complex',
                '[0:v]split=2[bgsrc][fgsrc];'
                ."[bgsrc]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},boxblur=20:2[bg];"
                ."[fgsrc]scale={$w}:{$h}:force_original_aspect_ratio=decrease[fg];"
                .'[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1[v]',
                '-map', '[v]',
            );
        } else {
            $filter = $mode === 'portrait'
                ? "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2,setsar=1"
                : "scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},setsar=1";
            array_push($args, '-vf', $filter, '-map', '0:v:0');
        }

        array_push(
            $args,
            '-map', '0:a?',
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $output,
        );

        return $args;
    }

    private function store(StreamMarker $marker, Filesystem $disk, string $output, string $signature): void
    {
        $clip = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $marker->clip_id) ?: 'clip';
        $path = 'clips/'.$marker->id.'/'.$clip.'-short-'.substr($signature, 0, 12).'.mp4';

        $stream = fopen($output, 'rb');
        if ($stream === false || ! $disk->writeStream($path, $stream)) {
            throw new RuntimeException('Could not write the Shorts cut to the '.config('clips.disk').' disk.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        $old = $marker->short_file_path;
        $marker->update([
            'short_file_path' => $path,
            'short_signature' => $signature,
            'short_bytes' => (int) filesize($output),
            'formatted_at' => now(),
            'format_error' => null,
        ]);

        if ($old !== null && $old !== $path) {
            $disk->delete($old);
        }
    }

    /**
     * ffmpeg needs a local file. A local disk gives its path; any other disk
     * is copied to a temporary file first.
     *
     * @return array{0: string, 1: bool} the path, and whether it is a temporary copy
     */
    private function localInput(Filesystem $disk, string $path): array
    {
        if (config('filesystems.disks.'.config('clips.disk').'.driver') === 'local') {
            return [$disk->path($path), false];
        }

        $tmp = sys_get_temp_dir().'/clip-src-'.$this->markerId.'-'.Str::random(8).'.mp4';
        $in = $disk->readStream($path);
        $out = fopen($tmp, 'wb');
        if (! is_resource($in) || $out === false) {
            throw new RuntimeException('Could not copy the clip file for ffmpeg.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return [$tmp, true];
    }

    private static function seconds(float $seconds): string
    {
        return number_format($seconds, 1, '.', '');
    }

    private static function firstLine(string $text): string
    {
        $line = trim((string) strtok(trim($text), "\n"));

        return $line === '' ? 'no error output' : Str::limit($line, 200);
    }
}
