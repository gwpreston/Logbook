<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;

/**
 * One day of a station's listed price for a grade (spec.md §7.34: the daily
 * low, high and close derived from its changes).
 */
final readonly class DailyPrice
{
    public function __construct(
        public DateTimeImmutable $day,
        public string $low,
        public string $high,
        public string $close,
    ) {
    }
}
