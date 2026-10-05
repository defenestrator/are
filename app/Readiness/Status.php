<?php

namespace App\Readiness;

enum Status: string
{
    /** Done. Nothing to do before the show. */
    case Ok = 'ok';

    /** Works, but something is off or degraded. Worth fixing. */
    case Warn = 'warn';

    /** Broken or missing. Fix before the show. */
    case Fail = 'fail';

    /** Not used with the current configuration, or not built yet. */
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Ready',
            self::Warn => 'Check',
            self::Fail => 'Missing',
            self::Skip => 'Not used',
        };
    }

    /** The Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'green',
            self::Warn => 'amber',
            self::Fail => 'red',
            self::Skip => 'zinc',
        };
    }

    /** Worst first, for sorting and for a group's overall status. */
    public function severity(): int
    {
        return match ($this) {
            self::Fail => 3,
            self::Warn => 2,
            self::Ok => 1,
            self::Skip => 0,
        };
    }
}
