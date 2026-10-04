<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A question or vote that the queue's rules refuse. The message is safe to
 * show the viewer.
 */
class QuestionRejected extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }

    public static function banned(): self
    {
        return new self('You are banned or timed out in this channel.');
    }

    public static function limitReached(): self
    {
        return new self('You have reached the suggestion limit');
    }

    public static function closed(): self
    {
        return new self('That question is no longer open for votes.');
    }
}
