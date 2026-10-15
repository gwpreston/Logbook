<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

use DateTimeImmutable;

/**
 * One spell of a part on the vehicle (spec.md §6 PartFitting): fitted on a
 * date and, once it comes off, removed on one, with what happened to it.
 * The record that fitted or removed it, if any, is a service record or a
 * modification, never both.
 */
final readonly class PartFitting
{
    public function __construct(
        public int $id,
        public int $partId,
        /** Calendar date. */
        public DateTimeImmutable $fittedOn,
        /** Kilometres. */
        public ?string $fittedOdometerKm,
        public ?int $fittedMaintenanceId,
        public ?int $fittedModificationId,
        public bool $fittedByRevert,
        /** Calendar date; null while fitted. */
        public ?DateTimeImmutable $removedOn,
        public ?string $removedOdometerKm,
        public ?int $removedMaintenanceId,
        public ?int $removedModificationId,
        public bool $removedByRevert,
        public ?RemovalOutcome $outcome,
        /** Canonical decimal, only when sold. */
        public ?string $soldPrice,
        public ?int $replacedByPartId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->removedOn === null;
    }

    public function fittedByRecord(): bool
    {
        return $this->fittedMaintenanceId !== null || $this->fittedModificationId !== null;
    }

    public function removedByRecord(): bool
    {
        return $this->removedMaintenanceId !== null || $this->removedModificationId !== null;
    }
}
