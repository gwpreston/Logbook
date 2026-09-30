<?php

declare(strict_types=1);

namespace Logbook\Service\Sharing;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\User\UserDirectory;

/**
 * "Added by" (spec.md §7.21): once a vehicle has anyone on it but its
 * owner, entries someone else added name them. Whether a vehicle is shared
 * is read once per vehicle and request.
 */
final class AuthorLabels
{
    /** @var array<int, bool> vehicle id => has shares */
    private array $shared = [];

    public function __construct(
        private readonly VehicleShareRepository $shares,
        private readonly UserDirectory $directory,
    ) {
    }

    /**
     * Who to name for an entry the viewer did not add: their display name,
     * '' for a former (deleted) user, or null when nothing is shown (the
     * viewer's own entry, or a vehicle nobody else is on).
     */
    public function label(User $viewer, Vehicle $vehicle, ?int $createdBy): ?string
    {
        if ($createdBy === $viewer->id || !$this->isShared($vehicle)) {
            return null;
        }

        return $createdBy === null ? '' : $this->directory->displayName($createdBy) ?? '';
    }

    public function isShared(Vehicle $vehicle): bool
    {
        return $this->shared[$vehicle->id] ??= $this->shares->sharedVehicleIds([$vehicle->id]) !== [];
    }

    public function forget(): void
    {
        $this->shared = [];
    }
}
