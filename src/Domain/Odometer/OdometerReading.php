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
        /** The incident whose odometer this is (Phase 27.1). */
        public ?int $incidentId = null,
        /** The issue whose odometer this is (Phase 40.1). */
        public ?int $issueId = null,
        /** The issue update whose odometer this is (Phase 40.1). */
        public ?int $issueUpdateId = null,
        /** The DVSA MOT test whose odometer this is (Phase 41). */
        public ?int $motTestId = null,
        /** The modification whose mileage this is (Phase 50.1). */
        public ?int $modificationId = null,
        /** The modification whose revert's mileage this is (Phase 50.1). */
        public ?int $modificationRevertId = null,
        /** The part fitting whose fitted mileage this is, when no record fitted it (Phase 50.1). */
        public ?int $partFittedId = null,
        /** The part fitting whose removed mileage this is, when no record removed it (Phase 50.1). */
        public ?int $partRemovedId = null,
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
            $this->incidentId !== null => [AttachmentOwner::Incident, $this->incidentId],
            $this->issueId !== null => [AttachmentOwner::Issue, $this->issueId],
            $this->modificationId !== null => [AttachmentOwner::Modification, $this->modificationId],
            $this->modificationRevertId !== null => [AttachmentOwner::Modification, $this->modificationRevertId],
            // Mileage when bought: the purchase paperwork is its receipt.
            $this->source === OdometerSource::Purchase => [AttachmentOwner::Purchase, $this->vehicleId],
            default => [AttachmentOwner::Odometer, $this->id],
        };
    }
}
