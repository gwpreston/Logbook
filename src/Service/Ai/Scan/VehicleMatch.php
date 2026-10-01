<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Vehicle\Vehicle;

/**
 * Which vehicle a document is for (spec.md §7.27 *Vehicle*).
 */
final readonly class VehicleMatch
{
    public function __construct(
        /** The vehicle the form opens on; null when the user must pick. */
        public ?Vehicle $vehicle,
        /** The registration the document shows, as printed, when it differs from the chosen vehicle's. */
        public ?string $otherRegistration = null,
        /** The user's vehicle with that registration, if any. */
        public ?Vehicle $documentVehicle = null,
    ) {
    }

    public function isMismatch(): bool
    {
        return $this->otherRegistration !== null;
    }
}
