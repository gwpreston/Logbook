<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

/**
 * How much a shared user may do with a vehicle (spec.md §7.21). The owner
 * has no share row and every ability.
 */
enum ShareLevel: string
{
    case View = 'view';
    case Log = 'log';
    case Manage = 'manage';

    /**
     * The abilities this level grants, before *Can see costs*.
     *
     * @return list<VehicleAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::View => [VehicleAbility::View],
            self::Log => [VehicleAbility::View, VehicleAbility::Log],
            self::Manage => [
                VehicleAbility::View,
                VehicleAbility::Log,
                VehicleAbility::Manage,
                VehicleAbility::ViewCosts,
                VehicleAbility::ViewOthersTrips,
                VehicleAbility::ViewIncidentDetails,
            ],
        };
    }

    /** Manage always sees costs; the others only when the share says so. */
    public function alwaysSeesCosts(): bool
    {
        return $this === self::Manage;
    }
}
