<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

use Logbook\Domain\Vehicle\VehicleStatus;

/**
 * Why a user can see one vehicle: they own it, or it is shared with them.
 * The access policy reads these for a user in one query.
 */
final readonly class VehicleGrant
{
    public function __construct(
        public int $vehicleId,
        public VehicleStatus $status,
        public bool $owned,
        /** Null when owned. */
        public ?VehicleShare $share,
    ) {
    }

    public function allows(VehicleAbility $ability): bool
    {
        return $this->owned || ($this->share !== null && in_array($ability, $this->share->abilities(), true));
    }

    /** Whether the user gets this vehicle's reminders. */
    public function notifies(): bool
    {
        return $this->owned || ($this->share !== null && $this->share->notify);
    }
}
