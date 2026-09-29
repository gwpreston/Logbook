<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\SegmentCost;
use Logbook\Support\Number\Decimal;

/**
 * The fuel cost of each closed full-to-full segment (spec.md §7.3, *Fuel
 * insights*): the segment's volume × the burned unit price, the
 * volume-weighted price of the opening full fill and the partials inside
 * (the fuel that was in the tank, as *Economy by grade* attributes it).
 * Read from the segments FuelEconomy already built; free charges at cost 0
 * count. Liquid fuel and electricity are separate series.
 *
 * Pure: no I/O, so every rule is unit-tested with worked examples.
 */
final class SegmentCostCalculator
{
    /**
     * @return list<SegmentCost> oldest first
     */
    public static function of(FuelHistory $history, EnergyKind $kind): array
    {
        $costs = [];
        foreach ($history->measured($kind) as $fill) {
            $segment = $fill->segment;
            $price = $segment?->burnedUnitPrice();
            if ($segment === null || $price === null || Decimal::compare($segment->distanceKm, '0') <= 0) {
                continue;
            }
            $cost = Decimal::multiply($segment->volume, $price, 6);
            $costs[] = new SegmentCost(
                closingEntryId: $fill->entry->id,
                endedAt: $segment->endedAt,
                distanceKm: $segment->distanceKm,
                volume: $segment->volume,
                unitPrice: $price,
                cost: $cost,
                costPerKm: Decimal::divide($cost, $segment->distanceKm, 8),
            );
        }

        return $costs;
    }
}
