<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

use DateTimeImmutable;
use Logbook\Support\Units\DistanceUnit;

/**
 * Mileage rates in effect from a date (spec.md §6 MileageRateSet). Rates are
 * canonical decimals per unit of $distanceUnit in $currency; the threshold
 * is in that unit per tax year.
 */
final readonly class MileageRateSetData
{
    public function __construct(
        /** Calendar date (midnight UTC). */
        public DateTimeImmutable $effectiveFrom,
        public DistanceUnit $distanceUnit,
        public string $currency,
        public string $carRate,
        /** Units per tax year at $carRate; null = no threshold. */
        public ?string $carThreshold = null,
        /** Required when a threshold is set. */
        public ?string $carRateAfter = null,
        /** Null = bikes use $carRate, with no threshold. */
        public ?string $bikeRate = null,
        /** Per passenger per unit. */
        public ?string $passengerRate = null,
        public ?string $employerCarRate = null,
        public ?string $employerBikeRate = null,
        public ?string $source = null,
    ) {
    }

    public function hasEmployerRates(): bool
    {
        return $this->employerCarRate !== null || $this->employerBikeRate !== null;
    }
}
