<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

/**
 * Business and private distance for a vehicle and period (spec.md §7.22),
 * in kilometres. Private is the mileage log's distance driven minus
 * business, never below 0: when business is more (readings too sparse),
 * private is null and $exceeds is set.
 */
final readonly class SplitFigures
{
    public function __construct(
        /** Every driver's business trips. */
        public string $businessKm,
        /** Distance driven from the mileage log; null when nothing was measurably driven. */
        public ?string $totalKm,
        public ?string $privateKm,
        public bool $exceeds,
        /** The viewer cannot see some of the trips: show the total only. */
        public bool $totalOnly = false,
    ) {
    }
}
