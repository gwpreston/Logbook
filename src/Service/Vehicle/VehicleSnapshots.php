<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\DueCounts;

/**
 * Builds VehicleSnapshots from the mileage log, the fuel history (all
 * full-to-full segments; electricity for an EV, liquid fuel otherwise) and
 * the due counts.
 */
final readonly class VehicleSnapshots
{
    public function __construct(
        private OdometerService $odometer,
        private FuelService $fuel,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @param array<int, int> $attention *Needs attention* item counts by vehicle id (AttentionReport::counts())
     * @return list<VehicleSnapshot> in the same order
     */
    public function of(array $vehicles, DueCounts $counts, array $attention = []): array
    {
        $fuel = $this->features->isEnabled(Feature::Fuel);

        return array_map(function (Vehicle $vehicle) use ($counts, $fuel, $attention): VehicleSnapshot {
            $kind = $vehicle->data->fuelType->primaryKind();

            return new VehicleSnapshot(
                $vehicle,
                $this->odometer->history($vehicle)->latest(),
                $fuel ? $this->fuel->history($vehicle)->summary($kind) : null,
                $counts->forVehicle($vehicle->id), // archived vehicles are never counted
                $attention[$vehicle->id] ?? 0,
            );
        }, $vehicles);
    }
}
