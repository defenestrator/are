<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A song request that the queue's rules refuse. The message is posted to chat
 * as the broadcaster, so it is fixed text plus song numbers only: never the
 * viewer's query, and not titles either (#128).
 */
class SongRequestRejected extends RuntimeException
{
    public static function notFound(): self
    {
        return new self('No requestable song matches that. See the list at '.route('music.index'));
    }

    /**
     * @param  list<int>  $trackIds
     */
    public static function ambiguous(array $trackIds): self
    {
        return new self('More than one song matches. Use the song number: '.implode(', ', array_map(fn (int $id) => "#{$id}", $trackIds)).'.');
    }

    public static function alreadyQueued(int $trackId): self
    {
        return new self("Song #{$trackId} is already in the request queue.");
    }

    public static function limitReached(int $limit): self
    {
        return new self("You already have {$limit} ".($limit === 1 ? 'song' : 'songs').' in the request queue. Wait for one to play.');
    }

    public static function banned(): self
    {
        return new self('You are banned or timed out in this channel.');
    }
}
