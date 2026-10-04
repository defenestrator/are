<?php

namespace App\ControlBus;

use Random\Randomizer;

/**
 * Draws one option with odds proportional to its weight. Weighted-random mode
 * passes each option's headcount, so every person adds exactly one to the
 * odds. Bound in the container so tests can fix the draw.
 */
class Picker
{
    public function __construct(private Randomizer $randomizer = new Randomizer) {}

    /**
     * @param  array<string, int>  $weights  option key => weight, all positive
     */
    public function pick(array $weights): string
    {
        $total = array_sum($weights);
        $roll = $this->randomizer->getInt(1, $total);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
