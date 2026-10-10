<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use Logbook\Domain\Vehicle\Vehicle;

/**
 * One vehicle in the digest's *Last month* (spec.md §7.11 *The monthly
 * briefing*). Distances are canonical kilometres.
 */
final readonly class LastMonthLine
{
    public function __construct(
        public Vehicle $vehicle,
        /** The month's distance driven (§7.7), or null when not measurable. */
        public ?string $distanceKm,
        /** The average of the 12 months before with a measurable distance, or null with fewer than 3. */
        public ?string $distanceAverageKm,
        /** Null without `ViewCosts`. */
        public ?VehicleSpend $costs,
    ) {
    }
}
