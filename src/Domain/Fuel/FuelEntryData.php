<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use DateTimeImmutable;

/**
 * A fill-up (or charge) as entered, validated and converted to storage units.
 * Decimals are canonical strings; all three of volume, price and total are
 * present (the form derives whichever one was left blank).
 */
final readonly class FuelEntryData
{
    public function __construct(
        /** UTC instant. */
        public DateTimeImmutable $filledAt,
        /** Kilometres. */
        public string $odometerKm,
        public Fuel $fuel,
        /** Litres, or kWh for electricity; always more than zero. */
        public string $volume,
        /** Per litre (or per kWh), in the vehicle's currency; zero is valid. */
        public string $pricePerUnit,
        /** In the vehicle's currency; zero is valid. */
        public string $totalCost,
        /** The tank was not filled up (the battery was not charged to full). */
        public bool $isPartial = false,
        /** At least one fill-up before this one was never logged. */
        public bool $isMissedPrevious = false,
        public ?string $station = null,
        public ?string $notes = null,
    ) {
    }
}
