<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

use DateTimeImmutable;

/**
 * One user's access to someone else's vehicle (spec.md §6 VehicleShare).
 */
final readonly class VehicleShare
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public int $userId,
        public ShareLevel $level,
        /** Always true for Manage. */
        public bool $canSeeCosts,
        /** Whether this user gets the vehicle's reminders. */
        public bool $notify,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @return list<VehicleAbility>
     */
    public function abilities(): array
    {
        $abilities = $this->level->abilities();
        if ($this->canSeeCosts && !in_array(VehicleAbility::ViewCosts, $abilities, true)) {
            $abilities[] = VehicleAbility::ViewCosts;
        }

        return $abilities;
    }
}
