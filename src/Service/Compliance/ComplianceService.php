<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use Logbook\Service\Access\AccessContext;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Compliance documents (spec.md §7.5): insurance, pollution certificates,
 * registration, inspections. Create and edit go through the same form and
 * an in-place update, so editing keeps the document's id and attachments.
 * A document with an odometer (an MOT certificate shows one) writes its
 * reading into the mileage series in the same transaction, at local noon on
 * its start date, exactly as a service record does (spec.md §7.2).
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class ComplianceService
{
    /** A dated document's odometer reading is placed at local noon on its start date. */
    private const string READING_TIME = 'T12:00';

    public function __construct(
        private ComplianceDocumentRepository $documents,
        private AttachmentService $attachments,
        private OdometerService $odometer,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AccessContext $author,
    ) {
    }

    /**
     * Every document with its status on $today, most urgent first.
     *
     * @param DateTimeImmutable $today the owner's calendar date
     * @param int $leadDays the owner's document lead time (ReminderPreferences)
     * @return list<DocumentState>
     */
    public function states(Vehicle $vehicle, DateTimeImmutable $today, int $leadDays = DocumentState::SOON_DAYS): array
    {
        return DocumentState::evaluateAll($this->documents->listForVehicle($vehicle->id), $today, $leadDays);
    }

    /**
     * @return list<ComplianceDocument> in creation order
     */
    public function list(Vehicle $vehicle): array
    {
        return $this->documents->listForVehicle($vehicle->id);
    }

    /**
     * @throws ComplianceDocumentNotFound
     */
    public function get(Vehicle $vehicle, int $id): ComplianceDocument
    {
        return $this->documents->find($vehicle->id, $id)
            ?? throw new ComplianceDocumentNotFound(sprintf('Compliance document %d not found.', $id));
    }

    /**
     * @param DateTimeZone $zone the owner's zone: the document's odometer
     *                           reading is recorded at noon on its start date there
     */
    public function create(
        Vehicle $vehicle,
        ComplianceDocumentData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
    ): ComplianceDocument {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data, $zone): int {
            $id = $this->documents->insert($vehicle->id, $data, $this->clock->now(), $this->author->authorId());
            $this->recordOdometer($vehicle, $id, $data, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Compliance, $id, $stored);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(
        Vehicle $vehicle,
        ComplianceDocument $document,
        ComplianceDocumentData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
    ): ComplianceDocument {
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $document, $data, $zone): void {
            $this->documents->update($vehicle->id, $document->id, $data, $this->clock->now());
            $this->recordOdometer($vehicle, $document->id, $data, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Compliance, $document->id, $stored);
        });

        return $this->get($vehicle, $document->id);
    }

    /**
     * Delete a document with its odometer reading and attachments.
     */
    public function delete(Vehicle $vehicle, ComplianceDocument $document): void
    {
        $this->transaction->run(function () use ($vehicle, $document): void {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Document, $document->id);
            $this->documents->delete($vehicle->id, $document->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Compliance, $document->id);
    }

    /**
     * Plausibility warning for the document's odometer within the whole series.
     */
    public function odometerWarning(Vehicle $vehicle, ComplianceDocument $document): ?OdometerWarning
    {
        return $this->odometer->warningForEntry($vehicle, OdometerSource::Document, $document->id);
    }

    /**
     * Create, move or remove the document's reading. The form refuses an
     * odometer without a start date; a document without one records none.
     */
    private function recordOdometer(Vehicle $vehicle, int $documentId, ComplianceDocumentData $data, DateTimeZone $zone): void
    {
        if ($data->odometerKm === null || $data->startOn === null) {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Document, $documentId);

            return;
        }

        $at = LocalTime::toUtc($data->startOn->format('Y-m-d') . self::READING_TIME, $zone)
            ?? DateTimeImmutable::createFromInterface($data->startOn);
        $this->odometer->recordForEntry($vehicle, OdometerSource::Document, $documentId, $data->odometerKm, $at);
    }
}
