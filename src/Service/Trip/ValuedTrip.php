<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\Trip;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * A business trip at the rates in effect on its date (spec.md §7.23),
 * derived on every read. Amounts are canonical decimals rounded to the
 * currency's minor unit; with no rate set in effect, $rateSet is null and
 * the trip has no value.
 */
final readonly class ValuedTrip
{
    /**
     * @param list<ClaimLine> $lines one, or two for a trip that crosses the threshold
     */
    public function __construct(
        public Trip $trip,
        public ?MileageRateSet $rateSet = null,
        /** The trip's distance in the rate set's unit. */
        public ?string $distance = null,
        public array $lines = [],
        public ?string $mileageAmount = null,
        public ?string $passengerRate = null,
        public ?string $passengerAmount = null,
        /** Null when the rate set has no employer rates. */
        public ?string $employerAmount = null,
    ) {
    }

    public function isValued(): bool
    {
        return $this->rateSet !== null;
    }

    public function currency(): ?string
    {
        return $this->rateSet?->data->currency;
    }

    public function unit(): ?DistanceUnit
    {
        return $this->rateSet?->data->distanceUnit;
    }

    /**
     * Mileage plus passengers: the approved amount for this trip.
     */
    public function amount(): ?string
    {
        if ($this->mileageAmount === null) {
            return null;
        }

        return Decimal::add($this->mileageAmount, $this->passengerAmount ?? '0');
    }

    public function isSplit(): bool
    {
        return count($this->lines) > 1;
    }
}
