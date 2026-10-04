<?php

namespace App\Enums;

/**
 * The canvas an overlay is designed for: the 16:9 stream or the 9:16 Shorts feed.
 */
enum OverlayLayout: string
{
    case Horizontal = 'horizontal';
    case Vertical = 'vertical';

    /**
     * Read `?layout=`. Anything unrecognised falls back to horizontal, so a
     * typo in an OBS source URL still shows something rather than an error.
     */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Horizontal) : self::Horizontal;
    }

    public function width(): int
    {
        return $this === self::Horizontal ? 1920 : 1080;
    }

    public function height(): int
    {
        return $this === self::Horizontal ? 1080 : 1920;
    }
}
