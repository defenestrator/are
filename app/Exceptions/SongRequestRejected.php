<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A song request that the queue's rules refuse. The message is safe to show
 * the viewer in chat.
 */
class SongRequestRejected extends RuntimeException
{
    public static function notFound(string $query): self
    {
        return new self("No requestable song matches \"{$query}\". See the list at ".route('music.index'));
    }

    /**
     * @param  list<string>  $titles
     */
    public static function ambiguous(array $titles): self
    {
        return new self('More than one song matches: '.implode(', ', $titles).'. Be more specific, or use the song number.');
    }

    public static function alreadyQueued(string $title): self
    {
        return new self("\"{$title}\" is already in the request queue.");
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
