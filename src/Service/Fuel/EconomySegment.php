<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;

/**
 * The stretch between two full fills: everything bought after the first one
 * (partials included, the closing full fill included) was burned over this
 * distance. Quantities are canonical decimals in storage units.
 */
final readonly class EconomySegment
{
    public function __construct(
        /** Kilometres from the opening full fill to the closing one. */
        public string $distanceKm,
        /** Litres (or kWh) bought within the segment. */
        public string $volume,
        /** Money spent within the segment, in the vehicle's currency. */
        public string $cost,
        /** Number of fill-ups bought within it (closing fill included). */
        public int $fills,
        /** When the closing fill happened. */
        public DateTimeImmutable $endedAt,
    ) {
    }
}
