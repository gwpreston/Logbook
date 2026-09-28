<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Vehicle\VehicleData;

/**
 * What the add-vehicle form yields: the vehicle and, if one was typed, its
 * current odometer in km (written as its first manual reading).
 */
final readonly class NewVehicle
{
    public function __construct(
        public VehicleData $data,
        public ?string $startingOdometerKm = null,
    ) {
    }
}
