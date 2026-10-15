<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\PartRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;

/**
 * Modifications (spec.md §7.39): logging, editing and deleting one with its
 * mileage, files and the parts it fits and takes off; reverting it and
 * undoing that; and *Make this a modification* from a service record or an
 * expense (#423), one at a time, the original deleted in the same
 * transaction.
 *
 * Callers pass a Vehicle already resolved for the signed-in user.
 */
final readonly class ModificationService
{
    public function __construct(
        private ModificationRepository $modifications,
        private PartRepository $parts,
        private PartService $partService,
        private DatedReading $reading,
        private AttachmentService $attachments,
        private AttachmentRepository $attachmentRows,
        private MaintenanceService $maintenance,
        private ExpenseService $expenses,
        private IssueService $issues,
        private TyreRepository $tyres,
        private AccessContext $author,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<Modification> current first, then reverted
     */
    public function list(Vehicle $vehicle): array
    {
        return $this->modifications->listForVehicle($vehicle->id);
    }

    /**
     * @throws ModificationNotFound
     */
    public function get(Vehicle $vehicle, int $id): Modification
    {
        return $this->modifications->find($vehicle->id, $id)
            ?? throw new ModificationNotFound(sprintf('Modification %d not found.', $id));
    }

    public function find(Vehicle $vehicle, int $id): ?Modification
    {
        return $this->modifications->find($vehicle->id, $id);
    }

    /**
     * @param RecordParts|null $parts the form's parts lists; null leaves parts alone
     * @throws PartChangeRefused
     */
    public function create(
        Vehicle $vehicle,
        ModificationData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
        ?RecordParts $parts = null,
    ): Modification {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data, $zone, $parts): int {
            $id = $this->insert($vehicle, $data, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Modification, $id, $stored);
            $this->applyParts($vehicle, $id, $data, $parts);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    /**
     * @param RecordParts|null $parts the form's parts lists; null only moves the fittings it made
     * @throws PartChangeRefused
     */
    public function update(
        Vehicle $vehicle,
        Modification $modification,
        ModificationData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
        ?RecordParts $parts = null,
    ): Modification {
        $id = $modification->id;
        $revertedOn = $modification->revertedOn;
        $this->attachments->saveWithFiles($files, function (array $stored) use (
            $vehicle,
            $revertedOn,
            $id,
            $data,
            $zone,
            $parts,
        ): void {
            $this->modifications->update($vehicle->id, $id, $data, $this->clock->now());
            $this->reading->record($vehicle, OdometerSource::Modification, $id, $data->odometerKm, $data->doneOn, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Modification, $id, $stored);
            $this->applyParts($vehicle, $id, $data, $parts);
            if ($revertedOn !== null && $revertedOn < $data->doneOn) {
                throw new PartChangeRefused('modification.refused.reverted_before', ['reverted' => $revertedOn]);
            }
        });

        return $this->get($vehicle, $modification->id);
    }

    /**
     * *Mark declared* / *Clear* (spec.md §7.39 *For your insurer*, #444, #445): when the insurer
     * was told, or null for *Not declared yet*. Nothing else about the record changes.
     *
     * @throws PartChangeRefused when the date is before the modification's own
     */
    public function declare(Vehicle $vehicle, Modification $modification, ?DateTimeImmutable $on): void
    {
        $data = $modification->data;
        if ($on !== null && $on < $data->doneOn) {
            throw new PartChangeRefused('modification.refused.declared_before', [
                'title' => $data->title,
                'done' => $data->doneOn,
            ]);
        }
        $this->modifications->update($vehicle->id, $modification->id, new ModificationData(
            title: $data->title,
            category: $data->category,
            doneOn: $data->doneOn,
            odometerKm: $data->odometerKm,
            cost: $data->cost,
            fittedBy: $data->fittedBy,
            notes: $data->notes,
            declaredOn: $on,
        ), $this->clock->now());
    }

    /**
     * Delete it with its readings and files. The fittings it made go (the
     * parts stay) and the removals it made are reopened.
     *
     * @throws PartChangeRefused
     */
    public function delete(Vehicle $vehicle, Modification $modification): void
    {
        $this->transaction->run(function () use ($vehicle, $modification): void {
            $this->partService->forgetRecord($vehicle, PartService::MODIFICATION, $modification->id);
            $this->modifications->delete($vehicle->id, $modification->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Modification, $modification->id);
    }

    /**
     * *Revert*: the modification stays in the record with a *Reverted*
     * badge; the parts it fitted that are ticked come off, and the stored
     * parts it took off that are ticked go back on, in the same save.
     *
     * @param array<int, PartRemoval> $remove by part id
     * @param list<int> $refit part ids
     * @throws PartChangeRefused
     */
    public function revert(
        Vehicle $vehicle,
        Modification $modification,
        DateTimeImmutable $on,
        ?string $km,
        ?string $note,
        array $remove,
        array $refit,
        DateTimeZone $zone,
    ): Modification {
        $this->transaction->run(function () use ($vehicle, $modification, $on, $km, $note, $remove, $refit, $zone): void {
            if ($on < $modification->data->doneOn) {
                throw new PartChangeRefused('modification.refused.reverted_before', ['reverted' => $on]);
            }
            $this->modifications->setReverted($vehicle->id, $modification->id, $on, $km, $note, $this->clock->now());
            $this->reading->record($vehicle, OdometerSource::ModificationRevert, $modification->id, $km, $on, $zone);
            $this->partService->revert($vehicle, $modification->id, $on, $km, $remove, $refit);
        });

        return $this->get($vehicle, $modification->id);
    }

    /**
     * *Undo revert*: clear it, reopen the removals it made and take back the
     * fittings it made.
     *
     * @throws PartChangeRefused
     */
    public function undoRevert(Vehicle $vehicle, Modification $modification): Modification
    {
        $this->transaction->run(function () use ($vehicle, $modification): void {
            $this->partService->followRevert($vehicle, $modification->id, null, null, true);
            $this->modifications->setReverted($vehicle->id, $modification->id, null, null, null, $this->clock->now());
            $this->reading->forget($vehicle, OdometerSource::ModificationRevert, $modification->id);
        });

        return $this->get($vehicle, $modification->id);
    }

    /**
     * Why a service record cannot become a modification, as a translation
     * key, or null when it can: its links have no place on a modification.
     */
    public function refusalFor(Vehicle $vehicle, MaintenanceEntry $entry): ?string
    {
        return match (true) {
            $entry->data->scheduleId !== null => 'modification.convert.refused.schedule',
            $entry->incidentId !== null => 'modification.convert.refused.incident',
            $this->parts->fittingsByRecord(PartService::MAINTENANCE, $entry->id) !== [] => 'modification.convert.refused.parts',
            $this->issues->fixedBy($entry->id) !== [] => 'modification.convert.refused.issues',
            $this->tyres->listChangesForEntries([$vehicle->id], [$entry->id]) !== [] => 'modification.convert.refused.tyres',
            default => null,
        };
    }

    /**
     * *Make this a modification* from a service record: the modification is
     * created, the record's files move to it, and the record is deleted.
     *
     * @throws PartChangeRefused when the record has links a modification cannot hold
     */
    public function fromMaintenance(
        Vehicle $vehicle,
        MaintenanceEntry $entry,
        ModificationData $data,
        DateTimeZone $zone,
    ): Modification {
        $refused = $this->refusalFor($vehicle, $entry);
        if ($refused !== null) {
            throw new PartChangeRefused($refused);
        }
        $id = $this->transaction->run(function () use ($vehicle, $entry, $data, $zone): int {
            $id = $this->insert($vehicle, $data, $zone, false);
            $this->attachmentRows->reassign(
                $vehicle->id,
                AttachmentOwner::Maintenance,
                $entry->id,
                AttachmentOwner::Modification,
                $id,
            );
            $this->maintenance->delete($vehicle, $entry, $zone);
            // After the record's own reading has gone, so the mileage isn't taken for a duplicate.
            $this->reading->record($vehicle, OdometerSource::Modification, $id, $data->odometerKm, $data->doneOn, $zone);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    /**
     * *Make this a modification* from an expense, as from a service record.
     */
    public function fromExpense(Vehicle $vehicle, ExpenseEntry $entry, ModificationData $data, DateTimeZone $zone): Modification
    {
        if ($entry->incidentId !== null) {
            throw new PartChangeRefused('modification.convert.refused.incident');
        }
        $id = $this->transaction->run(function () use ($vehicle, $entry, $data, $zone): int {
            $id = $this->insert($vehicle, $data, $zone, false);
            $this->attachmentRows->reassign(
                $vehicle->id,
                AttachmentOwner::Expense,
                $entry->id,
                AttachmentOwner::Modification,
                $id,
            );
            $this->expenses->delete($vehicle, $entry);
            $this->reading->record($vehicle, OdometerSource::Modification, $id, $data->odometerKm, $data->doneOn, $zone);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    private function insert(Vehicle $vehicle, ModificationData $data, DateTimeZone $zone, bool $withReading = true): int
    {
        $by = $this->author->authorId() ?? $vehicle->userId;
        $id = $this->modifications->insert($vehicle->id, $data, $this->clock->now(), $by);
        if ($withReading) {
            $this->reading->record($vehicle, OdometerSource::Modification, $id, $data->odometerKm, $data->doneOn, $zone);
        }

        return $id;
    }

    /**
     * @throws PartChangeRefused
     */
    private function applyParts(Vehicle $vehicle, int $id, ModificationData $data, ?RecordParts $parts): void
    {
        $this->partService->applyRecord(
            $vehicle,
            PartService::MODIFICATION,
            $id,
            $data->doneOn,
            $data->odometerKm,
            $parts,
            $vehicle->data->purchaseDate,
        );
    }
}
