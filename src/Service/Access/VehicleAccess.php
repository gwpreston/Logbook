<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * The one place that decides what a user may do with a vehicle, and which
 * vehicles a cross-vehicle read covers (spec.md §5 *Access policy*).
 * Actions and services ask; they never decide themselves.
 */
interface VehicleAccess
{
    public function can(User $user, VehicleAbility $ability, Vehicle $vehicle): bool;

    /**
     * The ids of the vehicles the user can view, in creation order.
     *
     * @phpstan-impure
     * @return list<int>
     */
    public function visibleVehicleIds(User $user, VehicleScope $scope): array;

    /**
     * The ids of the active vehicles whose reminders the user receives: the
     * ones they own and those shared with *Send me its reminders* (Phase 19).
     *
     * @phpstan-impure
     * @return list<int>
     */
    public function recipientVehicleIds(User $user): array;

    /**
     * Drop remembered answers: called when a request starts and after a
     * vehicle is added, archived, restored, deleted, shared, transferred or
     * left.
     */
    public function forget(): void;
}
