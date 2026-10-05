<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Vehicle\VehicleData;

/**
 * What the edit-vehicle form yields: the vehicle's details and its
 * *Mileage when bought*, which is a reading, not a vehicle column.
 */
final readonly class VehicleEdit
{
    public function __construct(
        public VehicleData $data,
        public PurchaseMileage $purchaseMileage,
    ) {
    }
}
