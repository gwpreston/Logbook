<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Service\Attachment\AttachmentService;
use Psr\Clock\ClockInterface;

/**
 * Compliance documents (spec.md §7.5): insurance, pollution certificates,
 * registration, inspections. Create and edit go through the same form and
 * an in-place update, so editing keeps the document's id and attachments.
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class ComplianceService
{
    public function __construct(
        private ComplianceDocumentRepository $documents,
        private AttachmentService $attachments,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every document with its status on $today, most urgent first.
     *
     * @param DateTimeImmutable $today the owner's calendar date
     * @return list<DocumentState>
     */
    public function states(Vehicle $vehicle, DateTimeImmutable $today): array
    {
        return DocumentState::evaluateAll($this->documents->listForVehicle($vehicle->id), $today);
    }

    /**
     * @throws ComplianceDocumentNotFound
     */
    public function get(Vehicle $vehicle, int $id): ComplianceDocument
    {
        return $this->documents->find($vehicle->id, $id)
            ?? throw new ComplianceDocumentNotFound(sprintf('Compliance document %d not found.', $id));
    }

    public function create(Vehicle $vehicle, ComplianceDocumentData $data): ComplianceDocument
    {
        $id = $this->documents->insert($vehicle->id, $data, $this->clock->now());

        return $this->get($vehicle, $id);
    }

    public function update(Vehicle $vehicle, ComplianceDocument $document, ComplianceDocumentData $data): ComplianceDocument
    {
        $this->documents->update($vehicle->id, $document->id, $data, $this->clock->now());

        return $this->get($vehicle, $document->id);
    }

    /**
     * Delete a document with its attachments.
     */
    public function delete(Vehicle $vehicle, ComplianceDocument $document): void
    {
        $this->documents->delete($vehicle->id, $document->id);
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Compliance, $document->id);
    }
}
