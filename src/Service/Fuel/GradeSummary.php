<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Number\Decimal;

/**
 * What one grade (or, with no grade, the fills where none was recorded)
 * cost and did on one vehicle, within one kind of energy (spec.md §7.3).
 * Quantities are canonical decimals in storage units (litres or kWh);
 * money is in the vehicle's currency.
 */
final readonly class GradeSummary
{
    /** Attributed segments needed before a grade's economy is shown. */
    public const int MIN_SEGMENTS = 2;

    public function __construct(
        /** Null: the fills of this kind with no grade recorded. */
        public ?FuelGrade $grade,
        public int $fills,
        /** Litres (kWh) bought. */
        public string $volume,
        /** Money spent, free (zero-cost) fills included. */
        public string $cost,
        /** Everything of this kind bought, for the share. */
        public string $kindVolume,
        /** Full-to-full segments burned entirely on this grade. */
        public int $segments = 0,
        public string $measuredDistanceKm = '0',
        public string $measuredVolume = '0',
    ) {
    }

    /**
     * Money per litre (kWh) bought, or null with nothing bought.
     */
    public function averagePricePerUnit(): ?string
    {
        return Decimal::compare($this->volume, '0') > 0 ? Decimal::divide($this->cost, $this->volume, 6) : null;
    }

    /**
     * Fraction (0–1) of the kind's volume that was this grade.
     */
    public function share(): float
    {
        return Decimal::compare($this->kindVolume, '0') > 0 ? (float) Decimal::divide($this->volume, $this->kindVolume, 6) : 0.0;
    }

    /**
     * Enough attributed segments to show an (indicative) economy figure.
     */
    public function hasEconomy(): bool
    {
        return $this->grade !== null
            && $this->segments >= self::MIN_SEGMENTS
            && Decimal::compare($this->measuredDistanceKm, '0') > 0;
    }
}
