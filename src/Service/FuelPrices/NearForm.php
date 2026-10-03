<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Place;
use Logbook\Domain\Station\Station;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Station\PlaceService;
use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Service\Vehicle\VehicleService;

/**
 * The choices and defaults of the *Cheapest near me* form (spec.md §7.34):
 * the user's active vehicles they can see (the one with the most recent
 * fill-up first), their places, their favourite and recent stations with a
 * position, and the grades the provider lists for the vehicle's fuel.
 */
final readonly class NearForm
{
    public function __construct(
        private VehicleService $vehicles,
        private FuelService $fuel,
        private PlaceService $places,
        private StationService $stations,
    ) {
    }

    /**
     * @return list<Vehicle> liquid-fuelled vehicles, the most recently filled first
     */
    public function vehicles(User $user): array
    {
        $rows = [];
        foreach ($this->vehicles->listFleet($user) as $vehicle) {
            if ($vehicle->data->fuelType->isElectric()) {
                continue;
            }
            $entries = $this->fuel->entries($vehicle);
            $last = $entries === [] ? null : $entries[array_key_last($entries)]->data->filledAt;
            $rows[] = ['vehicle' => $vehicle, 'last' => $last?->getTimestamp() ?? 0];
        }
        usort($rows, static fn (array $a, array $b): int => $b['last'] <=> $a['last'] ?: $a['vehicle']->id <=> $b['vehicle']->id);

        return array_map(static fn (array $row): Vehicle => $row['vehicle'], $rows);
    }

    /**
     * @return list<Place>
     */
    public function places(User $user): array
    {
        return $this->places->list($user);
    }

    /**
     * @return list<Station> favourite and recent stations with a position
     */
    public function stations(User $user): array
    {
        return array_values(array_map(
            static fn (StationListing $row): Station => $row->station,
            array_filter(
                $this->stations->choices($user, '', 50),
                static fn (StationListing $row): bool => $row->station->data->hasPosition(),
            ),
        ));
    }

    /**
     * The grades a provider can list for a vehicle's fuel (petrol and
     * diesel grades of its map), in picker order.
     *
     * @return list<FuelGrade>
     */
    public function grades(PriceProvider $provider, Vehicle $vehicle, FuelPriceSettings $settings): array
    {
        $mapped = array_values(array_unique(array_map(
            static fn (FuelGrade $g): string => $g->value,
            $provider->gradeMap($settings->chosenGrades()),
        )));
        $family = FuelGrade::defaultFamilyFor($vehicle->data->fuelType);

        return array_values(array_filter(
            FuelGrade::cases(),
            static fn (FuelGrade $g): bool => in_array($g->value, $mapped, true)
                && ($family === null || $g->family() === $family),
        ));
    }
}
