<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;

/**
 * The Phase 18.1 policy: every ability on one's own vehicles (`user_id`),
 * none on anyone else's. With one owner, that is the app as it always was.
 * Visible ids are one query per user and scope, remembered until forget().
 */
final class SingleOwnerVehicleAccess implements VehicleAccess
{
    /** @var array<string, list<int>> keyed "user:scope" */
    private array $visible = [];

    public function __construct(private readonly VehicleRepository $vehicles)
    {
    }

    public function can(User $user, VehicleAbility $ability, Vehicle $vehicle): bool
    {
        return $vehicle->userId === $user->id;
    }

    public function visibleVehicleIds(User $user, VehicleScope $scope): array
    {
        return $this->visible[$user->id . ':' . $scope->value] ??= $this->vehicles->idsOwnedBy($user->id, $scope->status());
    }

    public function forget(): void
    {
        $this->visible = [];
    }
}
