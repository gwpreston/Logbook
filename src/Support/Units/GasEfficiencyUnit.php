<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

/**
 * How a CNG vehicle's economy is shown (Phase 31): gas per distance
 * (kg/100 km, lower is better) or distance per kg (mi/kg, higher is
 * better). Gas is sold by mass, so it never converts to litres or gallons,
 * and like electricity it follows the user's distance unit.
 */
enum GasEfficiencyUnit: string
{
    case KgPer100Km = 'kg_per_100km';
    case MilesPerKg = 'mi_per_kg';

    public static function forDistanceUnit(DistanceUnit $unit): self
    {
        return match ($unit) {
            DistanceUnit::Kilometre => self::KgPer100Km,
            DistanceUnit::Mile => self::MilesPerKg,
        };
    }

    /**
     * Economy over a distance, or null when either side is not positive.
     */
    public function fromDistanceAndMass(float $km, float $kg): ?float
    {
        if ($km <= 0.0 || $kg <= 0.0) {
            return null;
        }

        return match ($this) {
            self::KgPer100Km => 100.0 * $kg / $km,
            self::MilesPerKg => $km / DistanceUnit::KM_PER_MILE / $kg,
        };
    }

    public function higherIsBetter(): bool
    {
        return $this === self::MilesPerKg;
    }
}
