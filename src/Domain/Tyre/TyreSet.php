<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

use DateTimeImmutable;

/**
 * An optional grouping of a vehicle's tyres for seasonal swaps (spec.md §6
 * TyreSet): *Winter wheels*, kept at a storage location.
 */
final readonly class TyreSet
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public TyreSetData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
