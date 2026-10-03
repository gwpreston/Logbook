<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Support\Number\Decimal;

/**
 * "Was it worth it?" before going (spec.md §7.34): one station against the
 * nearest selling the grade, every figure from the effective cost rule.
 *
 * - fuel saving = (nearest's price − this price) × usual fill
 * - extra distance = 2 × (this distance − nearest's), × 1.3 by road
 * - fuel for that = this detour cost − the nearest's
 * - actual saving = the nearest's effective cost − this one's
 */
final readonly class WorthIt
{
    public function __construct(
        public string $priceDifference,
        public string $fuelSaving,
        /** There and back in a straight line, km. */
        public float $extraKm,
        /** About this much by road, km. */
        public float $extraRoadKm,
        public string $fuelForThat,
        public string $actualSaving,
    ) {
    }

    public static function compare(EffectiveCost $nearest, EffectiveCost $candidate): self
    {
        $difference = Decimal::subtract($nearest->price, $candidate->price);
        $extraKm = 2 * ($candidate->km - $nearest->km);

        return new self(
            $difference,
            Decimal::multiply($difference, $candidate->fill, EffectiveCost::SCALE),
            $extraKm,
            $extraKm * EffectiveCost::ROAD_FACTOR,
            Decimal::subtract($candidate->detourCost, $nearest->detourCost),
            Decimal::subtract($nearest->total, $candidate->total),
        );
    }

    /** Above zero: the trip pays. */
    public function isWorthIt(): bool
    {
        return Decimal::compare($this->actualSaving, '0') > 0;
    }
}
