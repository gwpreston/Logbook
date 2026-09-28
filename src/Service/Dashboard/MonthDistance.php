<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use DateTimeImmutable;

/**
 * Kilometres driven in one calendar month (a bar of the mileage chart).
 */
final readonly class MonthDistance
{
    public function __construct(
        /** The 1st of the month (calendar date). */
        public DateTimeImmutable $month,
        /** Null when no vehicle has history by then. */
        public ?string $km,
    ) {
    }
}
