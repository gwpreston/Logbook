<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use DateTimeImmutable;
use InvalidArgumentException;

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
        /** Which grade of $fuel went in; null = not recorded (always valid). */
        public ?FuelGrade $grade = null,
        /**
         * The linked station (Phase 30.1, spec.md §7.33); null = none. When
         * set, $station holds its name as of the last save.
         */
        public ?int $stationId = null,
    ) {
        if ($grade !== null && $grade->family() !== $fuel) {
            throw new InvalidArgumentException(sprintf('Grade %s is not a %s grade.', $grade->value, $fuel->value));
        }
    }

    /**
     * The same fill-up linked to another station (or none), with the
     * station's name as its text.
     */
    public function withStation(?int $stationId, ?string $name): self
    {
        return new self(
            filledAt: $this->filledAt,
            odometerKm: $this->odometerKm,
            fuel: $this->fuel,
            volume: $this->volume,
            pricePerUnit: $this->pricePerUnit,
            totalCost: $this->totalCost,
            isPartial: $this->isPartial,
            isMissedPrevious: $this->isMissedPrevious,
            station: $name,
            notes: $this->notes,
            grade: $this->grade,
            stationId: $stationId,
        );
    }
}
