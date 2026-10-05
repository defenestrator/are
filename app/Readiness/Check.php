<?php

namespace App\Readiness;

/**
 * One line on the readiness page. Never holds a secret: values are reported
 * as "set" or "not set", and fixes name the env var or command to run.
 */
final readonly class Check
{
    /**
     * @param  string  $summary  What was found, in one line
     * @param  string|null  $fix  The exact fix, in one line (an env var, a command, a URL to visit)
     * @param  list<string>  $details  Extra lines, such as the missing scopes
     */
    public function __construct(
        public string $name,
        public Status $status,
        public string $summary,
        public ?string $fix = null,
        public array $details = [],
    ) {}

    public static function ok(string $name, string $summary, array $details = []): self
    {
        return new self($name, Status::Ok, $summary, null, $details);
    }

    public static function warn(string $name, string $summary, ?string $fix, array $details = []): self
    {
        return new self($name, Status::Warn, $summary, $fix, $details);
    }

    public static function fail(string $name, string $summary, ?string $fix, array $details = []): self
    {
        return new self($name, Status::Fail, $summary, $fix, $details);
    }

    public static function skip(string $name, string $summary, ?string $fix = null): self
    {
        return new self($name, Status::Skip, $summary, $fix);
    }
}
