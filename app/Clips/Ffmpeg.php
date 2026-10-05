<?php

namespace App\Clips;

use App\Readiness\Check;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Is the configured ffmpeg usable for Shorts formatting (#146)?
 *
 * `ffmpeg -version` is not enough: on bright-viper the snap build fails
 * headless ("unable to open display") whatever it is asked. So the probe
 * really encodes a tenth of a second of generated video and audio with
 * libx264 and AAC, the encoders FormatClipForShorts uses, to the null muxer.
 */
class Ffmpeg
{
    public const PROBE_TIMEOUT_SECONDS = 15;

    /**
     * The probe's result is reused this long, so refreshing the readiness
     * page while ffmpeg hangs ties up no more than one PHP worker per minute.
     */
    public const CACHE_SECONDS = 60;

    /**
     * @return list<string>
     */
    public static function probeArguments(): array
    {
        return [
            (string) config('clips.ffmpeg_binary'),
            '-hide_banner', '-nostdin', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'nullsrc=s=64x64',
            '-f', 'lavfi', '-i', 'anullsrc',
            '-t', '0.1',
            '-c:v', 'libx264', '-c:a', 'aac',
            '-f', 'null', '-',
        ];
    }

    public static function readinessCheck(): Check
    {
        $name = 'ffmpeg for Shorts formatting';
        $binary = (string) config('clips.ffmpeg_binary');
        $fix = 'Install a static, non-snap ffmpeg (e.g. the johnvansickle.com build, or apt install ffmpeg if a non-snap package is available) and set CLIPS_FFMPEG_BINARY to its absolute path, then php artisan optimize.';

        if (str_starts_with($binary, '/snap/')) {
            return Check::warn($name, "CLIPS_FFMPEG_BINARY is a snap ({$binary}); snap builds fail headless.", $fix);
        }

        $probe = self::probe($binary);

        if (isset($probe['error'])) {
            return Check::warn($name, "Could not run {$binary} ({$probe['error']}).", $fix);
        }

        if ($probe['exit'] === 0) {
            return Check::ok($name, "{$binary} encoded a test clip with libx264 and AAC.");
        }

        return Check::warn(
            $name,
            "{$binary} failed the encode probe (exit {$probe['exit']})".($probe['line'] !== '' ? ': '.$probe['line'] : '.'),
            $fix,
        );
    }

    /**
     * Run the probe, or reuse a result from the last CACHE_SECONDS.
     *
     * @return array{exit?: int, line?: string, error?: string}
     */
    private static function probe(string $binary): array
    {
        return Cache::remember('readiness:ffmpeg-probe:'.sha1($binary), self::CACHE_SECONDS, function () {
            try {
                $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run(self::probeArguments());
            } catch (Throwable $e) {
                return ['error' => class_basename($e)];
            }

            $line = trim((string) strtok(trim($result->errorOutput()."\n".$result->output()), "\n"));

            return ['exit' => (int) $result->exitCode(), 'line' => Str::limit($line, 160)];
        });
    }
}
