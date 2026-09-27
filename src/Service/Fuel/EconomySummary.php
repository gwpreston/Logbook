<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Number\Decimal;

/**
 * Totals and averages for one kind of energy (liquid fuel or electricity)
 * on one vehicle. Quantities are canonical decimals in storage units; money
 * is in the vehicle's currency.
 *
 * The average consumption is weighted: total measured distance over total
 * measured volume, never an average of per-fill figures.
 */
final readonly class EconomySummary
{
    public function __construct(
        public EnergyKind $kind,
        public int $fills,
        /** Everything bought (litres or kWh). */
        public string $totalVolume,
        /** Everything spent. */
        public string $totalCost,
        /** Kilometres covered by measured full-to-full segments. */
        public string $measuredDistanceKm,
        /** Litres (kWh) bought within measured segments. */
        public string $measuredVolume,
        /** Money spent within measured segments. */
        public string $measuredCost,
        public int $segments,
        public ?EconomySegment $lastSegment,
        /** Kilometres from the first fill-up's odometer to the last one's. */
        public string $trackedDistanceKm,
    ) {
    }

    public function hasEconomy(): bool
    {
        return $this->segments > 0 && Decimal::compare($this->measuredDistanceKm, '0') > 0;
    }

    /**
     * Money spent per litre (kWh) bought, or null with nothing bought.
     */
    public function averagePricePerUnit(): ?string
    {
        if (Decimal::compare($this->totalVolume, '0') <= 0) {
            return null;
        }

        return Decimal::divide($this->totalCost, $this->totalVolume, 6);
    }

    /**
     * Fuel cost per kilometre over the measured segments, or null.
     */
    public function costPerKm(): ?string
    {
        if (!$this->hasEconomy()) {
            return null;
        }

        return Decimal::divide($this->measuredCost, $this->measuredDistanceKm, 6);
    }
}
