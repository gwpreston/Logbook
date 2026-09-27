<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

/**
 * How an electric vehicle's efficiency is shown: energy per distance
 * (kWh/100 km, lower is better) or distance per energy (mi/kWh, km/kWh,
 * higher is better). Follows the user's distance unit, so no separate
 * preference is needed: kilometre users see kWh/100 km, mile users mi/kWh.
 */
enum ElectricEfficiencyUnit: string
{
    case KwhPer100Km = 'kwh_per_100km';
    case KmPerKwh = 'km_per_kwh';
    case MilesPerKwh = 'mi_per_kwh';

    public static function forDistanceUnit(DistanceUnit $unit): self
    {
        return match ($unit) {
            DistanceUnit::Kilometre => self::KwhPer100Km,
            DistanceUnit::Mile => self::MilesPerKwh,
        };
    }

    /**
     * Efficiency over a distance, or null when either side is not positive.
     */
    public function fromDistanceAndEnergy(float $km, float $kwh): ?float
    {
        if ($km <= 0.0 || $kwh <= 0.0) {
            return null;
        }

        return match ($this) {
            self::KwhPer100Km => 100.0 * $kwh / $km,
            self::KmPerKwh => $km / $kwh,
            self::MilesPerKwh => $km / DistanceUnit::KM_PER_MILE / $kwh,
        };
    }

    public function higherIsBetter(): bool
    {
        return $this !== self::KwhPer100Km;
    }
}
