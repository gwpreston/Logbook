<?php

declare(strict_types=1);

namespace Logbook\Service\Sharing;

use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleShare;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AiInsightRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;

/**
 * Sharing a vehicle, transferring it and leaving a share (spec.md §7.21).
 * The Actions have already checked `Own` (or, to leave, that there is a
 * share); this keeps the rules: the owner never has a share row, a disabled
 * user is never given one, Manage always sees costs.
 */
final readonly class SharingService
{
    public function __construct(
        private VehicleShareRepository $shares,
        private VehicleRepository $vehicles,
        private UserRepository $users,
        private VehicleAccess $access,
        private UserDirectory $directory,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AiInsightRepository $insights,
    ) {
    }

    /**
     * @return list<ShareRow> oldest first; shares of deleted users are gone with them
     */
    public function sharesOf(Vehicle $vehicle): array
    {
        $rows = [];
        foreach ($this->shares->listForVehicle($vehicle->id) as $share) {
            $user = $this->directory->find($share->userId);
            if ($user !== null) {
                $rows[] = new ShareRow($share, $user);
            }
        }

        return $rows;
    }

    public function shareOf(User $user, Vehicle $vehicle): ?VehicleShare
    {
        return $this->shares->find($vehicle->id, $user->id);
    }

    public function shareOfUserId(Vehicle $vehicle, int $userId): ?VehicleShare
    {
        return $this->shares->find($vehicle->id, $userId);
    }

    /**
     * Whether the vehicle has anyone on it but its owner ("Added by" shows then).
     */
    public function isShared(Vehicle $vehicle): bool
    {
        return $this->shares->sharedVehicleIds([$vehicle->id]) !== [];
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<int> those with at least one share
     */
    public function sharedAmong(array $vehicleIds): array
    {
        return $this->shares->sharedVehicleIds($vehicleIds);
    }

    public function add(Vehicle $vehicle, string $username, ShareLevel $level, bool $canSeeCosts, bool $notify): ?ShareRefusal
    {
        $user = $this->users->findByUsername(Username::normalise($username));
        if ($user === null) {
            return ShareRefusal::UnknownUser;
        }
        $refusal = match (true) {
            !$user->isActive() => ShareRefusal::Disabled,
            $user->id === $vehicle->userId => ShareRefusal::Owner,
            $this->shares->find($vehicle->id, $user->id) !== null => ShareRefusal::AlreadyShared,
            default => null,
        };
        if ($refusal !== null) {
            return $refusal;
        }

        $this->shares->insert($vehicle->id, $user->id, $level, $canSeeCosts, $notify, $this->clock->now());
        $this->access->forget();

        return null;
    }

    /**
     * @return bool false when that user has no share on the vehicle
     */
    public function update(Vehicle $vehicle, int $userId, ShareLevel $level, bool $canSeeCosts, bool $notify): bool
    {
        $share = $this->shares->find($vehicle->id, $userId);
        if ($share === null) {
            return false;
        }
        $this->shares->update($vehicle->id, $userId, $level, $canSeeCosts, $notify, $this->clock->now());
        $this->access->forget();
        // Their kept AI insights may quote this vehicle's costs (#373): made
        // again without them, never shown or sent from the old set.
        $hadCosts = in_array(VehicleAbility::ViewCosts, $share->abilities(), true);
        if ($hadCosts && !$canSeeCosts && !in_array(VehicleAbility::ViewCosts, $level->abilities(), true)) {
            $this->insights->delete($userId);
        }

        return true;
    }

    public function remove(Vehicle $vehicle, int $userId): void
    {
        $this->shares->delete($vehicle->id, $userId);
        $this->access->forget();
    }

    /**
     * A shared user's own choice: whether they get this vehicle's reminders.
     */
    public function setNotify(User $user, Vehicle $vehicle, bool $notify): void
    {
        $this->shares->setNotify($vehicle->id, $user->id, $notify, $this->clock->now());
        $this->access->forget();
    }

    /**
     * @return bool false when the user had no share to leave
     */
    public function leave(User $user, Vehicle $vehicle): bool
    {
        if ($this->shares->find($vehicle->id, $user->id) === null) {
            return false;
        }
        $this->remove($vehicle, $user->id);

        return true;
    }

    /**
     * Make another user the owner. Everything stays with the vehicle, each
     * entry still naming who added it. The old owner keeps a Manage share
     * (with its reminders) unless $keepAccess is false.
     */
    public function transfer(Vehicle $vehicle, string $username, bool $keepAccess): ?ShareRefusal
    {
        $to = $this->users->findByUsername(Username::normalise($username));
        if ($to === null) {
            return ShareRefusal::UnknownUser;
        }
        if (!$to->isActive()) {
            return ShareRefusal::Disabled;
        }
        if ($to->id === $vehicle->userId) {
            return ShareRefusal::Owner;
        }

        $this->transaction->run(function () use ($vehicle, $to, $keepAccess): void {
            $now = $this->clock->now();
            $this->shares->delete($vehicle->id, $to->id);
            $this->vehicles->transfer($vehicle->id, $vehicle->userId, $to->id, $now);
            if ($keepAccess) {
                $this->shares->insert($vehicle->id, $vehicle->userId, ShareLevel::Manage, true, true, $now);
            }
        });
        $this->access->forget();

        return null;
    }
}
