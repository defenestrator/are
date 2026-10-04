<?php

namespace App\ControlBus;

use Normalizer;

/**
 * A normalised game action, such as task("Write the README") or move(3).
 *
 * Two people who type the same action produce the same key, so they back the
 * same option, and retyping a vetoed option is caught in the common ways:
 * the key keeps letters and digits only, ignoring case, accents,
 * compatibility forms (full-width letters, ligatures), invisible format
 * characters, common Cyrillic, Greek and Armenian look-alikes, punctuation,
 * symbols and spaces, even inside words ("d-e l e t e" is "delete").
 *
 * This is a convenience, not a filter: leetspeak and rarer look-alikes still
 * get through. Moderator approval of free text is the real control.
 */
final readonly class Action
{
    /**
     * Letters that render like Latin ones, folded after lowercasing.
     */
    private const LOOKALIKES = [
        // Cyrillic
        'а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p',
        'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ї' => 'i', 'ј' => 'j', 'ѕ' => 's', 'ԁ' => 'd',
        'һ' => 'h', 'ӏ' => 'l', 'ԛ' => 'q', 'ԝ' => 'w', 'ѵ' => 'v',
        // Greek
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'η' => 'n', 'ι' => 'i', 'κ' => 'k', 'μ' => 'u', 'ν' => 'v', 'ο' => 'o',
        'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'ω' => 'w', 'ϲ' => 'c', 'ϳ' => 'j',
        // Armenian, and Latin dotless i
        'օ' => 'o', 'ս' => 'u', 'հ' => 'h', 'ո' => 'n', 'ց' => 'g', 'զ' => 'q', 'ı' => 'i',
    ];

    public function __construct(
        public string $verb,
        public string|int|null $argument = null,
    ) {}

    public function key(): string
    {
        if ($this->argument === null) {
            return $this->verb;
        }

        return $this->verb.':'.self::normalise((string) $this->argument);
    }

    public function label(): string
    {
        return $this->argument === null ? $this->verb : $this->verb.' '.$this->argument;
    }

    public static function normalise(string $text): string
    {
        // Decompose (compatibility forms too), drop the accents, recompose.
        $text = Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text;
        $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;

        // Zero-width and bidi format characters.
        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);

        $text = strtr(mb_strtolower($text), self::LOOKALIKES);

        // Letters and digits only: punctuation, symbols and spaces go, even
        // inside words.
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
    }
}
