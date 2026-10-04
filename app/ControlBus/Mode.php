<?php

namespace App\ControlBus;

/**
 * How a game turns chat into actions.
 */
enum Mode: string
{
    /** Each window, the action with the most people behind it runs. */
    case Democracy = 'democracy';

    /** Every action runs at once, rate-limited per person. */
    case Anarchy = 'anarchy';

    /** Each window, one action runs, drawn with odds equal to its share of people. */
    case WeightedRandom = 'weighted_random';

    public function label(): string
    {
        return match ($this) {
            self::Democracy => 'Democracy',
            self::Anarchy => 'Anarchy',
            self::WeightedRandom => 'Weighted random',
        };
    }

    public function usesWindows(): bool
    {
        return $this !== self::Anarchy;
    }
}
