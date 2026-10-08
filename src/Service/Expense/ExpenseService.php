<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use Logbook\Support\Database\Transaction;
use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Service\Webhook\WebhookEvents;
use Logbook\Service\Access\AccessContext;
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
        private AccessContext $author,
        private WebhookEvents $webhooks,
        private Transaction $transaction,
    ) {
    }

    /**
     * @return list<ExpenseEntry> by date, then in the order logged
     */
    public function entries(Vehicle $vehicle): array
    {
        return $this->entries->listForVehicle($vehicle->id);
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
            $by = $this->author->authorId() ?? $vehicle->userId;
            $id = $this->entries->insert($vehicle->id, $data, $this->clock->now(), $by);
            $this->attachments->record($vehicle, AttachmentOwner::Expense, $id, $stored);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryCreated, WebhookKind::Expense, $id);

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
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Expense, $entry->id);
        });

        return $this->get($vehicle, $entry->id);
    }

    /**
     * Delete an expense with its attachments.
     */
    public function delete(Vehicle $vehicle, ExpenseEntry $entry): void
    {
        $this->transaction->run(function () use ($vehicle, $entry): void {
            $this->entries->delete($vehicle->id, $entry->id);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryDeleted, WebhookKind::Expense, $entry->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Expense, $entry->id);
    }
}
