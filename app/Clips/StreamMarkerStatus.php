<?php

namespace App\Clips;

enum StreamMarkerStatus: string
{
    /** Twitch refused the marker (not live, VOD storage off, rate limit, ...). Nothing more happens. */
    case MarkerFailed = 'marker_failed';

    /** The marker exists; the clip job is queued. */
    case ClipPending = 'clip_pending';

    /** Twitch accepted Create Clip From VOD; waiting for Get Clips to return it. */
    case ClipProcessing = 'clip_processing';

    /** The clip exists and its download URLs are stored. */
    case ClipReady = 'clip_ready';

    /** The clip could not be made, or Twitch gave no download URL. See error. */
    case ClipFailed = 'clip_failed';

    public function label(): string
    {
        return match ($this) {
            self::MarkerFailed => 'Marker failed',
            self::ClipPending => 'Clip queued',
            self::ClipProcessing => 'Clipping',
            self::ClipReady => 'Clip ready',
            self::ClipFailed => 'Clip failed',
        };
    }

    /** A Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::ClipReady => 'green',
            self::MarkerFailed, self::ClipFailed => 'red',
            default => 'zinc',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::MarkerFailed, self::ClipReady, self::ClipFailed], true);
    }
}
