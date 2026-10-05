<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\VehicleData;

/**
 * What the add-vehicle form yields: the vehicle and, if one was typed, its
 * current odometer with the date it was read (written as its first manual
 * reading), its *Mileage when bought*, and whether its *First MOT due* date was filled in from the
 * suggestion because the field came in blank without JS.
 */
final readonly class NewVehicle
{
    public function __construct(
        public VehicleData $data,
        public ?StartingReading $startingReading = null,
        /** The suggested first MOT date the server filled in (no JS), for the flash. */
        public ?DateTimeImmutable $suggestedFirstInspection = null,
        /** *Mileage when bought* in canonical km (Phase 33.3), or null. */
        public ?string $purchaseKm = null,
    ) {
    }
}
