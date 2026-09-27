<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

/**
 * Display unit for distances. Storage is always kilometres.
 */
enum DistanceUnit: string
{
    /** International mile, exact by definition. */
    public const float KM_PER_MILE = 1.609344;

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
}
