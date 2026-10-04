<?php

namespace App\ControlBus;

/**
 * A normalised game action, such as task("Write the README") or move(3).
 * Two people who type the same action, in any case or spacing, produce the
 * same key, so they back the same option.
 */
final readonly class Action
{
    public function __construct(
        public string $verb,
        public string|int|null $argument = null,
    ) {}

    public function key(): string
    {
        if ($this->argument === null) {
            return $this->verb;
        }

        return $this->verb.':'.mb_strtolower((string) $this->argument);
    }

    public function label(): string
    {
        return $this->argument === null ? $this->verb : $this->verb.' '.$this->argument;
    }
}
