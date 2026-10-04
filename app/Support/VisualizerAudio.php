<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What the Three.js visualizer listens to, read from the query string.
 *
 *   ?audio=default        the system's default capture device
 *   ?audio=<label>        the capture device whose label matches (exact first,
 *                         then substring, case-insensitive), or a deviceId
 *   ?audio=file           the bundled demo track
 *   ?audio=none           no audio; the mesh just drifts
 *   ?gain=<0.1..10>       scales the level before it reaches the shader
 *
 * With no ?audio, /visualizer keeps its old behaviour (the bundled track,
 * started by a click) and /overlay/visualizer listens to nothing, because
 * nobody can click an OBS browser source on stream.
 *
 * The bundled track is only ever audible on /visualizer. On the overlay it
 * is analysed silently, so it can never leak into the stream mix.
 */
final readonly class VisualizerAudio
{
    public const MODE_DEVICE = 'device';

    public const MODE_FILE = 'file';

    public const MODE_NONE = 'none';

    public const MAX_DEVICE_LENGTH = 120;

    private function __construct(
        public string $mode,
        public string $device,
        public float $gain,
        public bool $audible,
    ) {}

    public static function fromRequest(Request $request, bool $overlay): self
    {
        $raw = $request->query('audio');
        $raw = is_string($raw) ? trim($raw) : '';

        [$mode, $device] = match (strtolower($raw)) {
            '' => [$overlay ? self::MODE_NONE : self::MODE_FILE, ''],
            'none', 'off' => [self::MODE_NONE, ''],
            'file' => [self::MODE_FILE, ''],
            'default' => [self::MODE_DEVICE, ''],
            default => [self::MODE_DEVICE, mb_substr($raw, 0, self::MAX_DEVICE_LENGTH)],
        };

        return new self(
            mode: $mode,
            device: $device,
            gain: self::gain($request->query('gain')),
            audible: $mode === self::MODE_FILE && ! $overlay,
        );
    }

    private static function gain(mixed $raw): float
    {
        if (! is_string($raw) || ! is_numeric($raw)) {
            return 1.0;
        }

        return max(0.1, min(10.0, (float) $raw));
    }
}
