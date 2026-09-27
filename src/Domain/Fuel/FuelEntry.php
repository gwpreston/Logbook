<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use DateTimeImmutable;

/**
 * A logged fill-up (spec.md §6 FuelEntry). Derived figures (distance,
 * consumption, cost per distance) are never stored: see Service\Fuel\FuelEconomy.
 */
final readonly class FuelEntry
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public FuelEntryData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isFull(): bool
    {
        return !$this->data->isPartial;
    }
}
