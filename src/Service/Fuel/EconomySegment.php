<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Number\Decimal;

/**
 * The stretch between two full fills: everything bought after the first one
 * (partials included, the closing full fill included) was burned over this
 * distance. Quantities are canonical decimals in storage units.
 */
final readonly class EconomySegment
{
    public function __construct(
        /** Kilometres from the opening full fill to the closing one. */
        public string $distanceKm,
        /** Litres (or kWh) bought within the segment. */
        public string $volume,
        /** Money spent within the segment, in the vehicle's currency. */
        public string $cost,
        /** Number of fill-ups bought within it (closing fill included). */
        public int $fills,
        /** When the closing fill happened. */
        public DateTimeImmutable $endedAt,
        /**
         * The grade that was burned over it (spec.md §7.3): the opening full
         * fill's, when it and every partial inside share it; null for a
         * mixed or unrecorded segment. The closing fill's grade never counts
         * (that fuel is burned in the next segment).
         */
        public ?FuelGrade $grade = null,
        /** The full fill it was measured from (the economy check links to it). */
        public ?FuelEntry $opening = null,
        /**
         * Price × volume of the fuel burned over it (spec.md §7.3, *Fuel
         * insights*): the opening full fill plus every partial inside, the
         * same attribution as $grade; the closing fill is not included.
         */
        public string $burnedValue = '0',
        /** Litres (kWh) of the opening full fill plus the partials inside. */
        public string $burnedVolume = '0',
    ) {
    }

    /**
     * The volume-weighted price per litre (kWh) of the fuel burned over it,
     * 6 places; null when nothing is known to have been burned.
     */
    public function burnedUnitPrice(): ?string
    {
        return Decimal::compare($this->burnedVolume, '0') > 0
            ? Decimal::divide($this->burnedValue, $this->burnedVolume, 6)
            : null;
    }
}
