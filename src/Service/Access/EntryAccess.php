<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * The own-entry rules (spec.md §5 *Access policy*, §7.21), on top of the
 * vehicle policy: under Log one changes only what one added, and one always
 * sees the amounts one typed. `createdBy` null is the vehicle's owner's.
 */
final readonly class EntryAccess
{
    public function __construct(private VehicleAccess $vehicles)
    {
    }

    /** Edit or delete this entry. */
    public function canChange(User $user, Vehicle $vehicle, ?int $createdBy): bool
    {
        return $this->vehicles->can($user, VehicleAbility::Manage, $vehicle)
            || ($this->vehicles->can($user, VehicleAbility::Log, $vehicle) && self::isOwn($user, $vehicle, $createdBy));
    }

    /** See this entry's own amount (not figures made from several entries). */
    public function canSeeAmount(User $user, Vehicle $vehicle, ?int $createdBy): bool
    {
        return $this->vehicles->can($user, VehicleAbility::ViewCosts, $vehicle)
            || ($this->vehicles->can($user, VehicleAbility::View, $vehicle) && self::isOwn($user, $vehicle, $createdBy));
    }

    public static function isOwn(User $user, Vehicle $vehicle, ?int $createdBy): bool
    {
        return ($createdBy ?? $vehicle->userId) === $user->id;
    }
}
