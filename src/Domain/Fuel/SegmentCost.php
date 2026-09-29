<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use DateTimeImmutable;

/**
 * What the fuel burned over one closed full-to-full segment cost (spec.md
 * §7.3, *Fuel insights*): its volume × the burned unit price. Decimals are
 * canonical strings; money is in the vehicle's currency.
 */
final readonly class SegmentCost
{
    public function __construct(
        /** The fill-up that closed the segment. */
        public int $closingEntryId,
        /** When it closed (the chart's x value). */
        public DateTimeImmutable $endedAt,
        /** Kilometres. */
        public string $distanceKm,
        /** Litres (kWh), the same volume its consumption uses. */
        public string $volume,
        /** Volume-weighted price per litre (kWh) of the fuel burned, 6 places. */
        public string $unitPrice,
        /** Volume × unit price, 6 places (rounded only for display). */
        public string $cost,
        /** Cost ÷ distance, 8 places. */
        public string $costPerKm,
    ) {
    }
}
