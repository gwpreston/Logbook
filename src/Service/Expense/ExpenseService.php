<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Psr\Clock\ClockInterface;

/**
 * Ad-hoc expenses (spec.md §7.7), with their attachments (a parking receipt,
 * a penalty notice): saved with the expense, deleted with it. Callers pass a
 * Vehicle already resolved for the signed-in owner.
 */
final readonly class ExpenseService
{
    public function __construct(
        private ExpenseEntryRepository $entries,
        private AttachmentService $attachments,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ExpenseEntryNotFound
     */
    public function get(Vehicle $vehicle, int $id): ExpenseEntry
    {
        return $this->entries->find($vehicle->id, $id)
            ?? throw new ExpenseEntryNotFound(sprintf('Expense %d not found.', $id));
    }

    public function create(Vehicle $vehicle, ExpenseEntryData $data, PendingUploads $files = new PendingUploads()): ExpenseEntry
    {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data): int {
            $id = $this->entries->insert($vehicle->id, $data, $this->clock->now());
            $this->attachments->record($vehicle, AttachmentOwner::Expense, $id, $stored);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(
        Vehicle $vehicle,
        ExpenseEntry $entry,
        ExpenseEntryData $data,
        PendingUploads $files = new PendingUploads(),
    ): ExpenseEntry {
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $entry, $data): void {
            $this->entries->update($vehicle->id, $entry->id, $data, $this->clock->now());
            $this->attachments->record($vehicle, AttachmentOwner::Expense, $entry->id, $stored);
        });

        return $this->get($vehicle, $entry->id);
    }

    /**
     * Delete an expense with its attachments.
     */
    public function delete(Vehicle $vehicle, ExpenseEntry $entry): void
    {
        $this->entries->delete($vehicle->id, $entry->id);
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Expense, $entry->id);
    }
}
