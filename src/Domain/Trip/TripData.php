<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

use DateTimeImmutable;

/**
 * A trip as entered and validated (spec.md §6 Trip, §7.22). Distances are
 * canonical decimals in kilometres; the distance is the whole trip (both
 * ways for a return).
 */
final readonly class TripData
{
    public function __construct(
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $travelledOn,
        public string $fromPlace,
        public string $toPlace,
        public bool $isReturn = false,
        /** The whole trip; 0 is valid. */
        public string $distanceKm = '0.000',
        /** Evidence only: a trip writes no odometer reading. */
        public ?string $odometerStartKm = null,
        public ?string $odometerEndKm = null,
        public bool $isBusiness = true,
        /** Required when business. */
        public ?string $purpose = null,
        /** Business passengers, for the passenger rate. */
        public int $passengers = 0,
        public ?string $notes = null,
    ) {
    }
}
