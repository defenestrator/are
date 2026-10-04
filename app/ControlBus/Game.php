<?php

namespace App\ControlBus;

use App\QuestionQueue;
use InvalidArgumentException;

/**
 * One chat-controlled game, as configured in config/bus.php.
 */
final readonly class Game
{
    /**
     * @param  array<string, array<string, mixed>>  $verbs  verb => ['argument' => none|text|integer|choice, 'min', 'max', 'options']
     */
    public function __construct(
        public string $key,
        public string $label,
        public Mode $defaultMode,
        public int $windowSeconds,
        public int $anarchyActions,
        public int $anarchyPerSeconds,
        public array $verbs,
    ) {}

    public static function find(?string $key): ?self
    {
        if ($key === null) {
            return null;
        }

        $config = config("bus.games.{$key}");

        return is_array($config) ? self::fromConfig($key, $config) : null;
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        $games = [];
        foreach ((array) config('bus.games') as $key => $config) {
            $games[$key] = self::fromConfig((string) $key, $config);
        }

        return $games;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            label: (string) ($config['label'] ?? $key),
            defaultMode: Mode::tryFrom((string) ($config['mode'] ?? '')) ?? Mode::Democracy,
            windowSeconds: max(1, (int) ($config['window_seconds'] ?? 30)),
            anarchyActions: max(1, (int) ($config['anarchy']['actions'] ?? 3)),
            anarchyPerSeconds: max(1, (int) ($config['anarchy']['per_seconds'] ?? 60)),
            verbs: (array) ($config['verbs'] ?? []),
        );
    }

    /**
     * The window length: the game's own, plus the lag of the slowest platform
     * feeding the bus, so a viewer on any platform sees the whole window.
     */
    public function windowLengthSeconds(): int
    {
        $latencies = config('bus.platform_latency_seconds', []);
        $slowest = 0;
        foreach ((array) config('bus.platforms', []) as $platform) {
            $slowest = max($slowest, (int) ($latencies[$platform] ?? 0));
        }

        return $this->windowSeconds + $slowest;
    }

    /**
     * Whether actions with this verb wait for a moderator before they are
     * published. Free text always does unless the verb opts out.
     */
    public function requiresApproval(string $verb): bool
    {
        $spec = $this->verbs[$verb] ?? [];

        return (bool) ($spec['approval'] ?? (($spec['argument'] ?? 'none') === 'text'));
    }

    /**
     * Parse "verb [argument]" into an action.
     *
     * @throws InvalidArgumentException with a message fit for chat
     */
    public function parse(string $text): Action
    {
        $parts = preg_split('/\s+/u', trim($text), 2);
        $verb = mb_strtolower($parts[0] ?? '');
        $rest = trim($parts[1] ?? '');

        if ($verb === '' || ! isset($this->verbs[$verb])) {
            throw new InvalidArgumentException('Try '.$this->usage().'.');
        }

        $spec = $this->verbs[$verb];

        return match ($spec['argument'] ?? 'none') {
            'none' => $rest === '' ? new Action($verb) : throw new InvalidArgumentException("!do {$verb} takes nothing after it."),
            'text' => new Action($verb, $this->text($verb, $rest, $spec)),
            'integer' => new Action($verb, $this->integer($verb, $rest, $spec)),
            'choice' => new Action($verb, $this->choice($verb, $rest, $spec)),
            default => throw new InvalidArgumentException("!do {$verb} is misconfigured."),
        };
    }

    public function usage(): string
    {
        $forms = [];
        foreach ($this->verbs as $verb => $spec) {
            $forms[] = '!do '.$verb.match ($spec['argument'] ?? 'none') {
                'text' => ' <text>',
                'integer' => ' <'.($spec['min'] ?? 0).'-'.($spec['max'] ?? 0).'>',
                'choice' => ' <'.implode('|', $spec['options'] ?? []).'>',
                default => '',
            };
        }

        return implode(', ', $forms);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function text(string $verb, string $rest, array $spec): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', QuestionQueue::clean($rest));
        $min = (int) ($spec['min'] ?? 1);
        $max = (int) ($spec['max'] ?? 200);
        $length = mb_strlen($text);

        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException("!do {$verb} needs {$min} to {$max} characters.");
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function integer(string $verb, string $rest, array $spec): int
    {
        $min = (int) ($spec['min'] ?? 0);
        $max = (int) ($spec['max'] ?? 0);

        if (! preg_match('/^-?\d{1,9}$/', $rest) || (int) $rest < $min || (int) $rest > $max) {
            throw new InvalidArgumentException("!do {$verb} needs a number from {$min} to {$max}.");
        }

        return (int) $rest;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function choice(string $verb, string $rest, array $spec): string
    {
        $options = array_map('strtolower', (array) ($spec['options'] ?? []));
        $choice = mb_strtolower($rest);

        if (! in_array($choice, $options, true)) {
            throw new InvalidArgumentException("!do {$verb} needs one of: ".implode(', ', $options).'.');
        }

        return $choice;
    }
}
