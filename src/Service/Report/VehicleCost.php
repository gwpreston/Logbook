<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Money\Money;

/**
 * One vehicle's spend in a report, with its own cost per distance.
 */
final readonly class VehicleCost
{
    public function __construct(
        public Vehicle $vehicle,
        public Money $total,
        public int $count,
        /** Kilometres driven in the period (canonical decimal), or null. */
        public ?string $distanceKm,
        /** Money per kilometre (canonical decimal), or null without distance. */
        public ?string $costPerKm,
        /** Percentage of the currency's total, 0–100 (display only). */
        public float $share,
        /** The two readings the distance is the difference of, for the working (spec.md §8). */
        public ?DistanceSpan $span = null,
        /** The total less build spend (Phase 50.1): what the rate divides. */
        public ?Money $running = null,
    ) {
    }
}
