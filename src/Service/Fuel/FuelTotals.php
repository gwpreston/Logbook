<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Support\Number\Decimal;

/**
 * A set of fill-ups added up (FuelStatistics): canonical decimals in
 * litres, kWh or kg and kilometres, and money in the vehicle's currency.
 */
final readonly class FuelTotals
{
    public function __construct(
        public int $fillUps,
        public string $volume,
        public string $spend,
        /** Kilometres of the measured segments the fills close. */
        public string $distanceKm,
        /** What those segments used. */
        public string $measuredVolume,
        /** Spend ÷ volume (6 places), or null without volume. */
        public ?string $pricePerUnit,
    ) {
    }

    public function hasEconomy(): bool
    {
        return Decimal::compare($this->distanceKm, '0') > 0
            && Decimal::compare($this->measuredVolume, '0') > 0;
    }
}
