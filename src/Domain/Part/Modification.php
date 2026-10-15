<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

use DateTimeImmutable;

/**
 * Build work on a vehicle (spec.md §6 Modification, §7.39): a remap, a set
 * of coilovers, a wrap. Its cost is build spend, never a running cost.
 */
final readonly class Modification
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public ModificationData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who logged it; null = a former user. */
        public ?int $createdBy = null,
        /** Calendar date, set once reverted. */
        public ?DateTimeImmutable $revertedOn = null,
        /** Kilometres at the revert. */
        public ?string $revertedOdometerKm = null,
        public ?string $revertedNote = null,
    ) {
    }

    public function isReverted(): bool
    {
        return $this->revertedOn !== null;
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function filesOwner(): array
    {
        return ['modification', $this->id];
    }
}
