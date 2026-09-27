<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

use Logbook\Support\Number\Decimal;

/**
 * Display unit for distances. Storage is always kilometres.
 *
 * The float methods serve display and derived figures; the decimal methods
 * convert stored and typed quantities exactly (odometer readings), so a
 * value typed in miles comes back unchanged.
 */
enum DistanceUnit: string
{
    /** International mile, exact by definition. */
    public const float KM_PER_MILE = 1.609344;
    public const string KM_PER_MILE_DECIMAL = '1.609344';

    case Kilometre = 'km';
    case Mile = 'mi';

    public function fromKm(float $km): float
    {
        return match ($this) {
            self::Kilometre => $km,
            self::Mile => $km / self::KM_PER_MILE,
        };
    }

    public function toKm(float $value): float
    {
        return match ($this) {
            self::Kilometre => $value,
            self::Mile => $value * self::KM_PER_MILE,
        };
    }

    /**
     * A canonical decimal in this unit → kilometres rounded to $scale places.
     */
    public function toKmDecimal(string $value, int $scale): string
    {
        return match ($this) {
            self::Kilometre => Decimal::round($value, $scale),
            self::Mile => Decimal::multiply($value, self::KM_PER_MILE_DECIMAL, $scale),
        };
    }

    /**
     * Kilometres (canonical decimal) → this unit rounded to $scale places.
     */
    public function fromKmDecimal(string $km, int $scale): string
    {
        return match ($this) {
            self::Kilometre => Decimal::round($km, $scale),
            self::Mile => Decimal::divide($km, self::KM_PER_MILE_DECIMAL, $scale),
        };
    }
}
