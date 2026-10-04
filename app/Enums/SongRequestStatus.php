<?php

namespace App\Enums;

/**
 * Where a song request is in the queue. Queued and Playing are "open": a
 * track with an open request cannot be requested again.
 */
enum SongRequestStatus: string
{
    case Queued = 'queued';
    case Playing = 'playing';
    case Played = 'played';
    case Skipped = 'skipped';

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Queued->value, self::Playing->value];
    }
}
