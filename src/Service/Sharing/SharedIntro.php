<?php

declare(strict_types=1);

namespace Logbook\Service\Sharing;

use Logbook\Domain\Access\ShareAllowance;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\ShareSummary;

/**
 * A shared user's first visit (spec.md §7.9): "Sam shared the Golf with
 * you" and what the share allows, once per share. A share is marked pending
 * when it is created (SharedIntroPending), so shares that exist already
 * never show it.
 */
final readonly class SharedIntro
{
    public function __construct(
        private SharedIntroPending $pending,
        private SharingService $sharing,
        private ShareSummary $summary,
        private VehicleRepository $vehicles,
        private UserRepository $users,
    ) {
    }

    /**
     * Seen: not shown again.
     */
    public function dismiss(int $userId, int $vehicleId): void
    {
        $this->pending->clear($userId, $vehicleId);
    }

    /**
     * The first vehicle with a share of theirs still to be introduced.
     */
    public function firstPending(User $user): ?Vehicle
    {
        foreach ($this->pending->ids($user->id) as $id) {
            $vehicle = $this->vehicles->findById($id);
            if ($vehicle !== null && $this->sharing->shareOf($user, $vehicle) !== null) {
                return $vehicle;
            }
        }

        return null;
    }

    /**
     * What to say above the vehicle, or null once seen (or for the owner).
     *
     * @return array{owner: string, allows: ShareAllowance}|null
     */
    public function card(User $user, Vehicle $vehicle): ?array
    {
        if (!in_array($vehicle->id, $this->pending->ids($user->id), true)) {
            return null;
        }
        $share = $this->sharing->shareOf($user, $vehicle);
        if ($share === null) {
            return null;
        }

        return [
            'owner' => $this->users->find($vehicle->userId)->displayName ?? '',
            'allows' => $this->summary->of($share->level, $share->canSeeCosts, $share->notify),
        ];
    }
}
