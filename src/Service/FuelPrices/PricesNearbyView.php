<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Place;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * What *Prices nearby* shows (spec.md §7.33 *Fuel stations page*): the
 * form's choices and the search, if it could run.
 */
final readonly class PricesNearbyView
{
    /**
     * @param list<Vehicle> $vehicles
     * @param list<Place> $places
     * @param array{lat: string, lng: string}|null $position echoed back for "here", never stored
     * @param list<FuelGrade> $grades
     */
    public function __construct(
        public PriceProvider $provider,
        public array $vehicles,
        public Vehicle $vehicle,
        public array $places,
        public string $from,
        public ?NearOrigin $origin,
        public ?array $position,
        public array $grades,
        public ?FuelGrade $grade,
        public NearSort $sort,
        public int $radius,
        public ?NearResult $result,
        public ?NearSaving $saving,
        /** No place and no position yet: the prompt to add a place or use the location. */
        public bool $needsPlace,
    ) {
    }

    /**
     * The query that opens *Cheapest near me* with the same search.
     *
     * @return array<string, string>
     */
    public function nearQuery(): array
    {
        return array_filter([
            'from' => $this->from,
            'lat' => $this->position['lat'] ?? '',
            'lng' => $this->position['lng'] ?? '',
            'vehicle' => (string) $this->vehicle->id,
            'grade' => $this->grade->value ?? '',
        ], static fn (string $v): bool => $v !== '');
    }
}
