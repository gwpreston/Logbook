<?php

declare(strict_types=1);

namespace Logbook\Domain\Odometer;

use DateTimeImmutable;

/**
 * One point in a vehicle's mileage series (spec.md §6 OdometerReading).
 * Manual readings are edited directly; others follow the entry that owns
 * them (fuelEntryId for fill-ups, maintenanceEntryId for maintenance,
 * complianceDocumentId for documents).
 */
final readonly class OdometerReading
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        /** Kilometres, canonical decimal. */
        public string $readingKm,
        /** UTC instant. */
        public DateTimeImmutable $recordedAt,
        public OdometerSource $source,
        public ?string $note,
        public ?int $fuelEntryId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?int $maintenanceEntryId = null,
        public ?int $complianceDocumentId = null,
    ) {
    }

    public function isManual(): bool
    {
        return $this->source === OdometerSource::Manual;
    }
}
