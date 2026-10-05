<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * What a vehicle lost over a period, from its value curve (spec.md §7.35).
 */
final readonly class PeriodDepreciation
{
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
    ) {
    }
}
