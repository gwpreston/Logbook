<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleGrant;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Repository\VehicleShareRepository;

/**
 * The Phase 19 policy (spec.md §5 *Access policy*, §7.21): every ability
 * on the vehicles one owns, a share's level (plus *Can see costs*) on the
 * vehicles shared with one, nothing on any other. Admins are not owners.
 * A disabled user has nothing. Ownership and shares are one query per user,
 * remembered until forget().
 */
final class SharedVehicleAccess implements VehicleAccess
{
    /** @var array<int, array<int, VehicleGrant>> user id => vehicle id => grant */
    private array $grants = [];

    public function __construct(private readonly VehicleShareRepository $shares)
    {
    }

    public function can(User $user, VehicleAbility $ability, Vehicle $vehicle): bool
    {
        if (!$user->isActive()) {
            return false;
        }
        if ($vehicle->userId === $user->id) {
            return true;
        }

        return ($this->grantsFor($user)[$vehicle->id] ?? null)?->allows($ability) ?? false;
    }

    public function visibleVehicleIds(User $user, VehicleScope $scope): array
    {
        $status = $scope->status();

        return $this->idsWhere(
            $user,
            static fn (VehicleGrant $grant): bool => $status === null || $grant->status === $status,
        );
    }

    public function recipientVehicleIds(User $user): array
    {
        return $this->idsWhere(
            $user,
            static fn (VehicleGrant $grant): bool => $grant->status === VehicleStatus::Active && $grant->notifies(),
        );
    }

    public function forget(): void
    {
        $this->grants = [];
    }

    /**
     * @param callable(VehicleGrant): bool $keep
     * @return list<int>
     */
    private function idsWhere(User $user, callable $keep): array
    {
        if (!$user->isActive()) {
            return [];
        }

        return array_values(array_map(
            static fn (VehicleGrant $grant): int => $grant->vehicleId,
            array_filter($this->grantsFor($user), $keep),
        ));
    }

    /**
     * @return array<int, VehicleGrant>
     */
    private function grantsFor(User $user): array
    {
        if (!isset($this->grants[$user->id])) {
            $this->grants[$user->id] = [];
            foreach ($this->shares->grantsFor($user->id) as $grant) {
                $this->grants[$user->id][$grant->vehicleId] = $grant;
            }
        }

        return $this->grants[$user->id];
    }
}
