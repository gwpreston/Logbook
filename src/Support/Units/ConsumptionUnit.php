<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

/**
 * How fuel consumption is shown. Internally consumption is derived from a
 * distance in km and a volume in litres; L/100km is the pivot for conversion.
 *
 * UK and US miles per gallon differ because the gallons differ
 * (4.546 L vs 3.785 L): 40 mpg (UK) is about 33.3 mpg (US).
 */
enum ConsumptionUnit: string
{
    case LitresPer100Km = 'l_per_100km';
    case KmPerLitre = 'km_per_l';
    case MpgUk = 'mpg_uk';
    case MpgUs = 'mpg_us';

    /**
     * Consumption over a distance, or null when either side is not positive
     * (there is nothing meaningful to show for 0 km or 0 L).
     */
    public function fromDistanceAndVolume(float $km, float $litres): ?float
    {
        if ($km <= 0.0 || $litres <= 0.0) {
            return null;
        }

        return $this->fromLitresPer100Km(100.0 * $litres / $km);
    }

    public function fromLitresPer100Km(float $litresPer100Km): float
    {
        if ($this === self::LitresPer100Km) {
            return $litresPer100Km;
        }

        // km/L and mpg are reciprocals of L/100km, scaled by the units' sizes.
        return $this->reciprocalFactor() / $litresPer100Km;
    }

    public function toLitresPer100Km(float $value): float
    {
        if ($this === self::LitresPer100Km) {
            return $value;
        }

        return $this->reciprocalFactor() / $value;
    }

    /**
     * Whether a bigger number means a more economical vehicle.
     */
    public function higherIsBetter(): bool
    {
        return $this !== self::LitresPer100Km;
    }

    /**
     * k such that value = k / (L/100km).
     */
    private function reciprocalFactor(): float
    {
        return match ($this) {
            self::LitresPer100Km => 1.0,
            self::KmPerLitre => 100.0,
            self::MpgUk => 100.0 * VolumeUnit::LITRES_PER_UK_GALLON / DistanceUnit::KM_PER_MILE,
            self::MpgUs => 100.0 * VolumeUnit::LITRES_PER_US_GALLON / DistanceUnit::KM_PER_MILE,
        };
    }
}
