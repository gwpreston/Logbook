<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Support\Number\Decimal;

/**
 * Effective cost (spec.md §7.34): the usual fill at the listed price plus
 * the fuel to get there and back at the same price. The detour is twice
 * the straight-line distance × 1.3, a road factor (a constant, decided
 * 2026-10-03, #137). Money is exact decimals; only the distance went
 * through floats.
 */
final readonly class EffectiveCost
{
    public const float ROAD_FACTOR = 1.3;
    /** Places kept on money until it is shown. */
    public const int SCALE = 4;

    public function __construct(
        /** Straight-line distance from the origin, km. */
        public float $km,
        /** Price per litre. */
        public string $price,
        /** Litres. */
        public string $fill,
        /** There and back by road, km. */
        public float $detourKm,
        /** Litres for the detour, or null without an economy. */
        public ?string $detourLitres,
        public string $fillCost,
        public string $detourCost,
        public string $total,
    ) {
    }

    public static function of(float $km, string $price, VehicleFuelProfile $profile): self
    {
        $detourKm = self::detour($km);
        $litres = $profile->litresFor($detourKm);
        $fillCost = Decimal::multiply($profile->usualFill, $price, self::SCALE);
        $detourCost = $litres === null ? '0' : Decimal::multiply($litres, $price, self::SCALE);

        return new self(
            $km,
            $price,
            $profile->usualFill,
            $detourKm,
            $litres,
            $fillCost,
            $detourCost,
            Decimal::add($fillCost, $detourCost),
        );
    }

    /**
     * There and back by road: 2 × straight line × 1.3.
     */
    public static function detour(float $km): float
    {
        return 2 * $km * self::ROAD_FACTOR;
    }
}
