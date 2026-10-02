<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * Where a PCP or lease stands against its mileage allowance (spec.md §7.32
 * *Mileage*). Distances are kilometres (canonical decimals), shown in the
 * agreement's own unit; the excess charge is per that unit.
 */
final readonly class MileagePosition
{
    public function __construct(
        public DistanceUnit $unit,
        /** The allowance over the whole agreement: annual × months ÷ 12. */
        public string $allowanceKm,
        public int $months,
        /** The day the car goes back. */
        public DateTimeImmutable $endsOn,
        /** The odometer at the start: as entered, else the reading nearest the agreement date. */
        public ?string $startKm,
        /** Latest reading − start odometer; null without both. */
        public ?string $distanceKm,
        /** The allowance used to date, pro rata by days. */
        public string $allowedToDateKm,
        /** Distance at the end: current reading + average daily distance × days left; null without a projection. */
        public ?string $projectedKm,
        /** Projected − allowance: positive over, negative under. */
        public ?string $excessKm,
        /** The projected excess × the charge per unit, to whole currency units; null when not over. */
        public ?Money $excessCharge,
        /** Per unit, as entered. */
        public ?string $chargePerUnit,
    ) {
    }

    /** Projected to finish over the allowance. */
    public function isOver(): bool
    {
        return $this->excessKm !== null && (float) $this->excessKm > 0;
    }

    /** How far under the allowance it is projected to finish, as a positive distance; null when not under. */
    public function underKm(): ?string
    {
        if ($this->excessKm === null || Decimal::compare($this->excessKm, '0') >= 0) {
            return null;
        }

        return Decimal::subtract('0', $this->excessKm);
    }

    /** How far over (or under, negative) as a percentage of the allowance; null without a projection. */
    public function excessPercent(): ?float
    {
        if ($this->excessKm === null || (float) $this->allowanceKm <= 0) {
            return null;
        }

        return (float) $this->excessKm / (float) $this->allowanceKm * 100;
    }

    /** The projected excess in the agreement's unit, rounded to 100 (the attention fingerprint). */
    public function excessRounded(): ?int
    {
        if ($this->excessKm === null) {
            return null;
        }

        return (int) (round($this->unit->fromKm((float) $this->excessKm) / 100) * 100);
    }
}
