<?php

declare(strict_types=1);

namespace Logbook\Domain\Odometer;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;

/**
 * One point in a vehicle's mileage series (spec.md §6 OdometerReading).
 * Manual readings are edited directly; others follow the entry that owns
 * them (fuelEntryId for fill-ups, maintenanceEntryId for maintenance,
 * complianceDocumentId for documents, tyreChangeId for tyre changes).
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
        public ?int $tyreChangeId = null,
        /** Who added a manual reading (Phase 19); a derived one's author is its entry's. */
        public ?int $createdBy = null,
    ) {
    }

    public function isManual(): bool
    {
        return $this->source === OdometerSource::Manual;
    }

    /**
     * Whose files this row shows: a manual reading's own, a derived
     * reading's owning entry's (spec.md §7.2). A tyre change takes no files,
     * so its reading counts its own (always none).
     *
     * @return array{0: AttachmentOwner, 1: int}
     */
    public function filesOwner(): array
    {
        return match (true) {
            $this->fuelEntryId !== null => [AttachmentOwner::Fuel, $this->fuelEntryId],
            $this->maintenanceEntryId !== null => [AttachmentOwner::Maintenance, $this->maintenanceEntryId],
            $this->complianceDocumentId !== null => [AttachmentOwner::Compliance, $this->complianceDocumentId],
            default => [AttachmentOwner::Odometer, $this->id],
        };
    }
}
