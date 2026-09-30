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
        /**
         * The segment consumption (litres or kWh per 100 km, 6 places) the
         * owner confirmed as right on an economy check (spec.md §7.3); null
         * when never confirmed. Not part of the form data: editing keeps it.
         */
        public ?string $economyConfirmed = null,
        /** Who added it (Phase 19); null = the vehicle's owner, or a former user. */
        public ?int $createdBy = null,
    ) {
    }

    public function isFull(): bool
    {
        return !$this->data->isPartial;
    }
}
