<?php

namespace App\Clips;

use App\Readiness\Check;
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

        try {
            $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run(self::probeArguments());
        } catch (Throwable $e) {
            return Check::warn($name, "Could not run {$binary} (".class_basename($e).').', $fix);
        }

        if ($result->successful()) {
            return Check::ok($name, "{$binary} encoded a test clip with libx264 and AAC.");
        }

        $line = trim((string) strtok(trim($result->errorOutput()."\n".$result->output()), "\n"));

        return Check::warn(
            $name,
            "{$binary} failed the encode probe (exit {$result->exitCode()})".($line !== '' ? ': '.Str::limit($line, 160) : '.'),
            $fix,
        );
    }
}
