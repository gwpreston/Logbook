<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

use DateTimeImmutable;

/**
 * One tyre of a vehicle (spec.md §6 Tyre). Status and position are the
 * stored result of replaying the vehicle's tyre changes.
 */
final readonly class Tyre
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public TyreData $data,
        public TyreStatus $status,
        /** While fitted. */
        public ?TyrePosition $position,
        public ?int $setId,
        /** While retired. */
        public ?TyreRetireReason $retiredReason,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isFitted(): bool
    {
        return $this->status === TyreStatus::Fitted;
    }

    public function isStored(): bool
    {
        return $this->status === TyreStatus::Stored;
    }

    public function isRetired(): bool
    {
        return $this->status === TyreStatus::Retired;
    }
}
