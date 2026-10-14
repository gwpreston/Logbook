<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Service\Vehicle\ValuePoint;
use Logbook\Support\Money\Money;

/**
 * What a vehicle lost over a period, from its value curve (spec.md §7.35).
 */
final readonly class PeriodDepreciation
{
    /**
     * @param list<ValuePoint> $startPoints the value points the start value is read from: its own, or the two it lies between
     * @param list<ValuePoint> $stopPoints the same for the stop value
     */
    public function __construct(
        /** The loss as a cost; a gain is negative. */
        public Money $amount,
        /** The first day measured (later than the period's when cut to the first value point). */
        public DateTimeImmutable $from,
        /** The last day measured (the latest value point's when cut there). */
        public DateTimeImmutable $to,
        public bool $cutAtStart,
        /** Measured only up to the latest value point: "depreciation to 1 Mar 2026". */
        public bool $cutAtEnd,
        /** The value at the start of the first day measured, for the working (spec.md §8). */
        public Money $startValue,
        /** The value the loss is measured to: at the start of the day after the last (or the latest point's day). */
        public Money $stopValue,
        /** The day the stop value is read at. */
        public DateTimeImmutable $stopOn,
        public array $startPoints,
        public array $stopPoints,
    ) {
    }
}
