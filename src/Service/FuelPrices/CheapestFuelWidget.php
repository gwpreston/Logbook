<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Station\Place;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * The dashboard's *Cheapest fuel* widget (spec.md §7.34 *Dashboard
 * widget*): the three cheapest by effective cost near a place.
 */
final readonly class CheapestFuelWidget
{
    /**
     * @param list<Place> $places
     */
    public function __construct(
        public array $places,
        public ?Place $place,
        public ?Vehicle $vehicle,
        public ?NearResult $result,
    ) {
    }
}
