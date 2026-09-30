<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;

/**
 * A vehicle access policy for tests (spec.md §5 *Access policy*): the
 * single-owner rule, except for the vehicles given exact abilities with
 * set(), whoever owns them. Proves that routes, lists and figures follow
 * the policy, not `vehicles.user_id`, so Phase 19 only needs a new policy.
 *
 * Install it before the app handles its first request (the container keeps
 * the services it has built).
 */
final class ConfigurableVehicleAccess implements VehicleAccess
{
    /** @var array<int, list<VehicleAbility>> vehicle id => exactly these abilities */
    private array $overrides = [];

    public function __construct(private readonly VehicleRepository $vehicles)
    {
    }

    public function set(Vehicle $vehicle, VehicleAbility ...$abilities): void
    {
        $this->overrides[$vehicle->id] = array_values($abilities);
    }

    /**
     * Every ability except these, on this vehicle.
     */
    public function except(Vehicle $vehicle, VehicleAbility ...$abilities): void
    {
        $this->set($vehicle, ...array_filter(
            VehicleAbility::cases(),
            static fn (VehicleAbility $ability): bool => !in_array($ability, $abilities, true),
        ));
    }

    public function can(User $user, VehicleAbility $ability, Vehicle $vehicle): bool
    {
        return isset($this->overrides[$vehicle->id])
            ? in_array($ability, $this->overrides[$vehicle->id], true)
            : $vehicle->userId === $user->id;
    }

    public function visibleVehicleIds(User $user, VehicleScope $scope): array
    {
        $ids = $this->vehicles->idsOwnedBy($user->id, null);
        $ids = array_merge($ids, array_keys($this->overrides));
        $status = $scope->status();
        $visible = array_filter(
            $this->vehicles->listByIds(array_values(array_unique($ids))),
            fn (Vehicle $vehicle): bool => $this->can($user, VehicleAbility::View, $vehicle)
                && ($status === null || $vehicle->status === $status),
        );

        return array_values(array_map(static fn (Vehicle $vehicle): int => $vehicle->id, $visible));
    }

    public function forget(): void
    {
    }
}
