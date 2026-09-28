<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\EconomySummary;
use Logbook\Service\Reminder\VehicleDueCount;

/**
 * A vehicle at a glance (spec.md §7.1, §7.8): current odometer, average
 * economy of its main kind of energy, and what is due. The garage cards and
 * the dashboard's vehicle tiles.
 */
final readonly class VehicleSnapshot
{
    public function __construct(
        public Vehicle $vehicle,
        public ?OdometerReading $latest,
        /** Null while fuel is switched off or nothing was logged. */
        public ?EconomySummary $economy,
        public VehicleDueCount $due,
    ) {
    }

    public function isElectric(): bool
    {
        return $this->vehicle->data->fuelType->isElectric();
    }

    public function hasEconomy(): bool
    {
        return $this->economy !== null && $this->economy->hasEconomy();
    }
}
