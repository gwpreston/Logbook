<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

/**
 * Display unit for fuel volumes. Storage is always litres.
 */
enum VolumeUnit: string
{
    /** Imperial gallon, exact by definition. */
    public const float LITRES_PER_UK_GALLON = 4.54609;
    /** US liquid gallon (231 cubic inches), exact by definition. */
    public const float LITRES_PER_US_GALLON = 3.785411784;

    case Litre = 'l';
    case UkGallon = 'gal_uk';
    case UsGallon = 'gal_us';

    public function litresPerUnit(): float
    {
        return match ($this) {
            self::Litre => 1.0,
            self::UkGallon => self::LITRES_PER_UK_GALLON,
            self::UsGallon => self::LITRES_PER_US_GALLON,
        };
    }

    public function fromLitres(float $litres): float
    {
        return $litres / $this->litresPerUnit();
    }

    public function toLitres(float $value): float
    {
        return $value * $this->litresPerUnit();
    }
}
