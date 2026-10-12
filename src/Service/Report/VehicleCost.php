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
        /** Percentage of the vehicles above zero, 0–100 (display only); null below zero (#387). */
        public ?float $share,
    ) {
    }
}
