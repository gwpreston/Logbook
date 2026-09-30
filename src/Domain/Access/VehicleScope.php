<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

use Logbook\Domain\Vehicle\VehicleStatus;

/**
 * Which of the vehicles a user can see a cross-vehicle read covers.
 */
enum VehicleScope: string
{
    case Active = 'active';
    case Archived = 'archived';
    case All = 'all';

    public static function of(bool $includeArchived): self
    {
        return $includeArchived ? self::All : self::Active;
    }

    /**
     * The one status this scope keeps, or null for every vehicle.
     */
    public function status(): ?VehicleStatus
    {
        return match ($this) {
            self::Active => VehicleStatus::Active,
            self::Archived => VehicleStatus::Archived,
            self::All => null,
        };
    }
}
