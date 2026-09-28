<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

use Logbook\Support\Money\Currency;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;

/**
 * Numbers for CSV export: plain canonical decimals ("1234.5": no grouping,
 * a dot, no unit), in the owner's units, precise enough to convert back to
 * the stored value exactly (spec.md §7.7).
 */
final class CsvNumber
{
    /** Places for converted quantities: far below the stored 3 places once converted back. */
    public const int QUANTITY_SCALE = 6;
    /** Places for converted prices (stored with 6 places per litre). */
    public const int PRICE_SCALE = 9;

    /**
     * An amount with the currency's usual places ("45.20" in GBP, "1000" in
     * JPY), and more only when it was stored with them ("45.125").
     */
    public static function money(string $amount, string $currency): string
    {
        $trimmed = Decimal::trim($amount);
        $places = str_contains($trimmed, '.') ? strlen($trimmed) - strpos($trimmed, '.') - 1 : 0;

        return Decimal::round($trimmed, max($places, Currency::fractionDigits($currency)));
    }

    /**
     * Kilometres in the owner's distance unit.
     */
    public static function distance(string $km, DistanceUnit $unit): string
    {
        return Decimal::trim($unit->fromKmDecimal($km, self::QUANTITY_SCALE));
    }

    /**
     * Litres in the owner's volume unit (kWh stay kWh).
     */
    public static function volume(string $litres, VolumeUnit $unit, bool $electric): string
    {
        return Decimal::trim($electric ? $litres : $unit->fromLitresDecimal($litres, self::QUANTITY_SCALE));
    }

    /**
     * A price per litre as a price per the owner's volume unit (per kWh stays per kWh).
     */
    public static function unitPrice(string $perLitre, VolumeUnit $unit, bool $electric): string
    {
        return Decimal::trim($electric ? $perLitre : $unit->pricePerUnit($perLitre, self::PRICE_SCALE));
    }
}
