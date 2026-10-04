<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A half-open range of whole days, [from, until), in the app timezone.
 * Half-open means a timestamp at exactly midnight on Monday belongs to the
 * week it starts, never to the week before.
 */
final class DateRange
{
    public const PRESETS = [
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'last_30_days' => 'Last 30 days',
    ];

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
        public readonly ?string $preset,
    ) {}

    /**
     * this_week and last_week are ISO weeks (Monday to Sunday);
     * last_30_days is today and the 29 days before it.
     */
    public static function preset(string $preset, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $monday = $now->startOfWeek(CarbonImmutable::MONDAY);

        return match ($preset) {
            'this_week' => new self($monday, $monday->addWeek(), $preset),
            'last_week' => new self($monday->subWeek(), $monday, $preset),
            'last_30_days' => new self($now->startOfDay()->subDays(29), $now->startOfDay()->addDay(), $preset),
            default => throw new InvalidArgumentException("Unknown date range preset [{$preset}]."),
        };
    }

    /** From the start of $from to the end of $to, both Y-m-d and inclusive. */
    public static function days(string $from, string $to): self
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);

        if (! $start || ! $end || $end->lt($start)) {
            throw new InvalidArgumentException('The range must run from one date to the same or a later date.');
        }

        return new self($start, $end->addDay(), null);
    }

    /** The last day in the range, for display and form values. */
    public function lastDay(): CarbonImmutable
    {
        return $this->until->subDay();
    }

    public function label(): string
    {
        return $this->from->format('D j M Y').' to '.$this->lastDay()->format('D j M Y');
    }
}
