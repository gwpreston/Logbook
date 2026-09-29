<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

/**
 * Common combinations of display units. A preset only fills in the four
 * individual preferences; users can then mix and match (e.g. km with mpg).
 */
enum UnitPreset: string
{
    case Metric = 'metric';
    case Uk = 'uk';
    case Us = 'us';

    public function distance(): DistanceUnit
    {
        return match ($this) {
            self::Metric => DistanceUnit::Kilometre,
            self::Uk, self::Us => DistanceUnit::Mile,
        };
    }

    public function volume(): VolumeUnit
    {
        return match ($this) {
            self::Metric, self::Uk => VolumeUnit::Litre,
            self::Us => VolumeUnit::UsGallon,
        };
    }

    public function consumption(): ConsumptionUnit
    {
        return match ($this) {
            self::Metric => ConsumptionUnit::LitresPer100Km,
            self::Uk => ConsumptionUnit::MpgUk,
            self::Us => ConsumptionUnit::MpgUs,
        };
    }

    /**
     * Tread depth: millimetres, except 32nds of an inch in the US.
     */
    public function depth(): DepthUnit
    {
        return $this === self::Us ? DepthUnit::ThirtySecond : DepthUnit::Millimetre;
    }

    /**
     * The preset matching these units exactly, if any.
     */
    public static function matching(
        DistanceUnit $distance,
        VolumeUnit $volume,
        ConsumptionUnit $consumption,
        DepthUnit $depth,
    ): ?self {
        foreach (self::cases() as $preset) {
            if (
                $preset->distance() === $distance
                && $preset->volume() === $volume
                && $preset->consumption() === $consumption
                && $preset->depth() === $depth
            ) {
                return $preset;
            }
        }

        return null;
    }
}
