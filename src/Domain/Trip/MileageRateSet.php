<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

use DateTimeImmutable;

/**
 * A user's dated mileage rates (spec.md §6 MileageRateSet, §7.23). The set
 * in effect on a date is the latest one starting on or before it.
 */
final readonly class MileageRateSet
{
    public function __construct(
        public int $id,
        public int $userId,
        public MileageRateSetData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
