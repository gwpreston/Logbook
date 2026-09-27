<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

use Logbook\Support\Number\Decimal;

/**
 * Display unit for fuel volumes. Storage is always litres.
 *
 * The decimal methods convert typed and stored quantities exactly, including
 * prices per unit (a price per US gallon is stored per litre with enough
 * places that it converts back unchanged).
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

    /**
     * The same factor as an exact canonical decimal.
     */
    public function litresPerUnitDecimal(): string
    {
        return match ($this) {
            self::Litre => '1',
            self::UkGallon => '4.54609',
            self::UsGallon => '3.785411784',
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

    /**
     * A volume typed in this unit → litres rounded to $scale places.
     */
    public function toLitresDecimal(string $value, int $scale): string
    {
        return Decimal::multiply($value, $this->litresPerUnitDecimal(), $scale);
    }

    /**
     * Litres → this unit rounded to $scale places.
     */
    public function fromLitresDecimal(string $litres, int $scale): string
    {
        return Decimal::divide($litres, $this->litresPerUnitDecimal(), $scale);
    }

    /**
     * A price per this unit → the price per litre, rounded to $scale places.
     */
    public function pricePerLitre(string $pricePerUnit, int $scale): string
    {
        return Decimal::divide($pricePerUnit, $this->litresPerUnitDecimal(), $scale);
    }

    /**
     * A price per litre → the price per this unit, rounded to $scale places.
     */
    public function pricePerUnit(string $pricePerLitre, int $scale): string
    {
        return Decimal::multiply($pricePerLitre, $this->litresPerUnitDecimal(), $scale);
    }
}
