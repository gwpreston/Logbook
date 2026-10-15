<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\PartOrigin;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\PartRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Service\Webhook\WebhookEvents;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Psr\Clock\ClockInterface;

/**
 * Parts and their fittings (spec.md §7.39): adding, editing and deleting a
 * part with its files; fitting and removing it without a record (with a
 * reading of its own); and the fittings and removals a service record or a
 * modification makes, which follow that record's date and mileage and go
 * when it does. After every change the part's fittings are checked
 * (PartTimeline) and its status replayed and stored.
 *
 * Callers pass a Vehicle already resolved for the signed-in user. The
 * record-driven methods run inside the record's own transaction.
 */
final readonly class PartService
{
    public const string MAINTENANCE = 'maintenance';
    public const string MODIFICATION = 'modification';

    public function __construct(
        private PartRepository $parts,
        private OdometerReadingRepository $readings,
        private DatedReading $reading,
        private AttachmentService $attachments,
        private AccessContext $author,
        private Transaction $transaction,
        private ClockInterface $clock,
        private WebhookEvents $webhooks,
    ) {
    }

    /**
     * @param list<PartStatus> $statuses none = every status
     * @return list<Part> by name
     */
    public function list(Vehicle $vehicle, array $statuses = []): array
    {
        return $this->parts->listForVehicle($vehicle->id, $statuses);
    }

    /**
     * @throws PartNotFound
     */
    public function get(Vehicle $vehicle, int $id): Part
    {
        return $this->parts->find($vehicle->id, $id)
            ?? throw new PartNotFound(sprintf('Part %d not found.', $id));
    }

    public function find(Vehicle $vehicle, int $id): ?Part
    {
        return $this->parts->find($vehicle->id, $id);
    }

    /**
     * @return list<PartFitting> oldest first
     */
    public function fittings(Part $part): array
    {
        return $this->parts->fittingsOf($part->id);
    }

    /**
     * @param list<Part> $parts
     * @return array<int, list<PartFitting>> by part id
     */
    public function fittingsFor(array $parts): array
    {
        return $this->parts->fittingsFor(array_map(static fn (Part $p): int => $p->id, $parts));
    }

    /**
     * The fittings a record made or ended, with their parts.
     *
     * @param 'maintenance'|'modification' $kind
     * @return list<array{part: Part, fitting: PartFitting}>
     */
    public function byRecord(Vehicle $vehicle, string $kind, int $recordId): array
    {
        $parts = [];
        foreach ($this->parts->listForVehicle($vehicle->id) as $part) {
            $parts[$part->id] = $part;
        }
        $rows = [];
        foreach ($this->parts->fittingsByRecord($kind, $recordId) as $fitting) {
            if (isset($parts[$fitting->partId])) {
                $rows[] = ['part' => $parts[$fitting->partId], 'fitting' => $fitting];
            }
        }

        return $rows;
    }

    /**
     * The fittings several records made or ended, with their parts, in one read.
     *
     * @param 'maintenance'|'modification' $kind
     * @param list<int> $recordIds
     * @return array<int, list<array{part: Part, fitting: PartFitting}>> by record id
     */
    public function byRecords(Vehicle $vehicle, string $kind, array $recordIds): array
    {
        $parts = [];
        foreach ($this->parts->listForVehicle($vehicle->id) as $part) {
            $parts[$part->id] = $part;
        }
        $rows = [];
        foreach ($this->parts->fittingsByRecords($kind, $recordIds) as $recordId => $fittings) {
            foreach ($fittings as $fitting) {
                if (isset($parts[$fitting->partId])) {
                    $rows[$recordId][] = ['part' => $parts[$fitting->partId], 'fitting' => $fitting];
                }
            }
        }

        return $rows;
    }

    /**
     * The overview card (spec.md §7.39 *Pages*), or null when the vehicle
     * has no modifications and no parts.
     *
     * @param list<Modification> $modifications the vehicle's, current first
     */
    public function card(Vehicle $vehicle, array $modifications, string $currency, DateTimeZone $zone): ?PartsCard
    {
        $parts = $this->parts->listForVehicle($vehicle->id);
        if ($modifications === [] && $parts === []) {
            return null;
        }
        $current = array_values(array_filter($modifications, static fn (Modification $m): bool => !$m->isReverted()));
        $spend = Money::zero($currency);
        foreach ($modifications as $modification) {
            $spend = $spend->add(Money::of($modification->data->cost, $currency));
        }
        $warranted = array_values(array_filter($parts, static fn (Part $p): bool
            => $p->data->hasWarranty() && $p->status !== PartStatus::Gone));
        $inWarranty = 0;
        if ($warranted !== []) {
            $fittings = $this->parts->fittingsForVehicle($vehicle->id);
            $km = $this->currentKm($vehicle);
            $today = LocalTime::today($this->clock, $zone);
            foreach ($warranted as $part) {
                if (PartWarranty::of($part, $fittings[$part->id] ?? [], $km, $today)?->active === true) {
                    $inWarranty++;
                }
            }
        }

        return new PartsCard(array_slice($current, 0, 5), count($current), $spend, $inWarranty);
    }

    /**
     * The vehicle's latest reading, the end of an open fitting's span.
     */
    public function currentKm(Vehicle $vehicle): ?string
    {
        return $this->readings->latestKm($vehicle->id);
    }

    /**
     * @param list<PartFitting> $fittings oldest first
     */
    public function distance(Vehicle $vehicle, array $fittings, DateTimeZone $zone): PartDistance
    {
        return PartDistance::of($fittings, $this->currentKm($vehicle), LocalTime::today($this->clock, $zone));
    }

    /**
     * @param list<PartFitting> $fittings oldest first
     */
    public function warranty(Vehicle $vehicle, Part $part, array $fittings, DateTimeZone $zone): ?PartWarranty
    {
        return PartWarranty::of($part, $fittings, $this->currentKm($vehicle), LocalTime::today($this->clock, $zone));
    }

    /**
     * Add a part on its own: a spare bought ahead, or one *Already on the
     * car* when $fitOn is given (fitted without a record, #418).
     */
    public function create(
        Vehicle $vehicle,
        PartData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
        ?DateTimeImmutable $fitOn = null,
        ?string $fitKm = null,
    ): Part {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use (
            $vehicle,
            $data,
            $zone,
            $fitOn,
            $fitKm,
        ): int {
            $id = $this->insertPart($vehicle, $data);
            $this->attachments->record($vehicle, AttachmentOwner::Part, $id, $stored);
            if ($fitOn !== null) {
                $part = $this->get($vehicle, $id);
                $fitting = $this->parts->insertFitting($id, $fitOn, $fitKm, null, null, $this->clock->now());
                $this->reading->record($vehicle, OdometerSource::PartFitted, $fitting, $fitKm, $fitOn, $zone);
                $this->settle($vehicle, $part, false);
            }

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(Vehicle $vehicle, Part $part, PartData $data, PendingUploads $files = new PendingUploads()): Part
    {
        // The origin is the part's history, not the form's.
        $data = new PartData(
            name: $data->name,
            brand: $data->brand,
            partNumber: $data->partNumber,
            serialNumber: $data->serialNumber,
            supplier: $data->supplier,
            boughtOn: $data->boughtOn,
            price: $data->price,
            condition: $data->condition,
            origin: $part->data->origin,
            warrantyMonths: $data->warrantyMonths,
            warrantyKm: $data->warrantyKm,
            warrantyFrom: $data->warrantyFrom,
            warrantyProvider: $data->warrantyProvider,
            warrantyTerms: $data->warrantyTerms,
            notes: $data->notes,
            warrantyReminder: $data->warrantyReminder,
        );
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $part, $data): void {
            $this->parts->update($vehicle->id, $part->id, $data, $this->clock->now());
            $this->attachments->record($vehicle, AttachmentOwner::Part, $part->id, $stored);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Part, $part->id);
        });

        return $this->get($vehicle, $part->id);
    }

    /**
     * Delete a part with its fittings, their readings and its files. The
     * records that fitted or removed it are kept.
     */
    public function delete(Vehicle $vehicle, Part $part): void
    {
        $this->transaction->run(function () use ($vehicle, $part): void {
            $this->parts->delete($vehicle->id, $part->id);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryDeleted, WebhookKind::Part, $part->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Part, $part->id);
    }

    /**
     * *Fit* / *Fit again* without a record (#418): a stored part goes on.
     *
     * @throws PartChangeRefused
     */
    public function fit(Vehicle $vehicle, Part $part, DateTimeImmutable $on, ?string $km, DateTimeZone $zone): void
    {
        $this->transaction->run(function () use ($vehicle, $part, $on, $km, $zone): void {
            $this->requireStatus($part, PartStatus::Stored);
            $id = $this->parts->insertFitting($part->id, $on, $km, null, null, $this->clock->now());
            $this->reading->record($vehicle, OdometerSource::PartFitted, $id, $km, $on, $zone);
            $this->settle($vehicle, $part);
        });
    }

    /**
     * *Remove* without a record: a fitted part comes off.
     *
     * @throws PartChangeRefused
     */
    public function remove(
        Vehicle $vehicle,
        Part $part,
        DateTimeImmutable $on,
        ?string $km,
        PartRemoval $removal,
        DateTimeZone $zone,
    ): void {
        $this->transaction->run(function () use ($vehicle, $part, $on, $km, $removal, $zone): void {
            $open = PartTimeline::open($this->parts->fittingsOf($part->id))
                ?? throw new PartChangeRefused('part.refused.not_fitted', ['part' => $part->label()]);
            $this->setRemoved($open, $on, $km, null, null, $removal);
            $this->reading->record($vehicle, OdometerSource::PartRemoved, $open->id, $km, $on, $zone);
            $this->settle($vehicle, $part);
        });
    }

    /**
     * Apply a record's *Parts fitted* and *Parts removed* lists, and move
     * the fittings and removals it already made to its date and mileage.
     * $parts null only moves them (a save without the lists). Run inside
     * the record's transaction; a refusal rolls the record back too.
     *
     * @param 'maintenance'|'modification' $kind
     * @throws PartChangeRefused
     */
    public function applyRecord(
        Vehicle $vehicle,
        string $kind,
        int $recordId,
        DateTimeImmutable $on,
        ?string $km,
        ?RecordParts $parts,
        ?DateTimeImmutable $purchasedOn = null,
    ): void {
        $now = $this->clock->now();
        $byId = [];
        foreach ($this->parts->listForVehicle($vehicle->id) as $part) {
            $byId[$part->id] = $part;
        }
        $touched = [];
        $fresh = [];
        foreach ($this->parts->fittingsByRecord($kind, $recordId) as $fitting) {
            $part = $byId[$fitting->partId] ?? null;
            if ($part === null) {
                continue;
            }
            if (self::fittedBy($fitting, $kind, $recordId) && !$fitting->fittedByRevert) {
                if ($parts !== null && !in_array($part->id, $parts->fit, true)) {
                    $this->parts->deleteFitting($fitting->id);
                } else {
                    $this->parts->moveFitted($fitting->id, $on, $km, $now);
                }
                $touched[$part->id] = $part;
            }
            if (self::removedBy($fitting, $kind, $recordId) && !$fitting->removedByRevert) {
                $removal = $parts === null ? self::removalOf($fitting) : ($parts->remove[$part->id] ?? null);
                if ($removal === null) {
                    $this->parts->clearRemoved($fitting->id, $now);
                } else {
                    $this->setRemoved($fitting, $on, $km, $kind, $recordId, $removal);
                }
                $touched[$part->id] = $part;
            }
        }

        if ($parts !== null) {
            $fittedHere = [];
            $removedHere = [];
            foreach ($this->parts->fittingsByRecord($kind, $recordId) as $fitting) {
                if (self::fittedBy($fitting, $kind, $recordId)) {
                    $fittedHere[$fitting->partId] = true;
                }
                if (self::removedBy($fitting, $kind, $recordId)) {
                    $removedHere[$fitting->partId] = true;
                }
            }
            foreach ($parts->remove as $partId => $removal) {
                $part = $byId[$partId] ?? null;
                if ($part === null || isset($removedHere[$partId])) {
                    continue;
                }
                $open = PartTimeline::open($this->parts->fittingsOf($partId))
                    ?? throw new PartChangeRefused('part.refused.not_fitted', ['part' => $part->label()]);
                $this->setRemoved($open, $on, $km, $kind, $recordId, $removal);
                $touched[$partId] = $part;
            }
            foreach ($parts->fit as $partId) {
                $part = $byId[$partId] ?? null;
                if ($part === null || isset($fittedHere[$partId])) {
                    continue;
                }
                if (PartTimeline::status($this->parts->fittingsOf($partId)) !== PartStatus::Stored) {
                    throw new PartChangeRefused('part.refused.not_stored', ['part' => $part->label()]);
                }
                $this->parts->insertFitting($partId, $on, $km, $kind, $recordId, $now);
                $touched[$partId] = $part;
            }
            foreach ($parts->fitNew as $data) {
                $id = $this->insertPart($vehicle, $data);
                $this->parts->insertFitting($id, $on, $km, $kind, $recordId, $now);
                $touched[$id] = $this->get($vehicle, $id);
                $fresh[$id] = true;
            }
            foreach ($parts->cameOff as $row) {
                $data = self::withOrigin($row->part, PartOrigin::Original);
                $id = $this->insertPart($vehicle, $data);
                $from = $purchasedOn !== null && $purchasedOn <= $on ? $purchasedOn : $on;
                $fitting = $this->parts->insertFitting($id, $from, null, null, null, $now);
                $this->parts->setRemoved(
                    $fitting,
                    $on,
                    $km,
                    $kind,
                    $recordId,
                    $row->removal->outcome,
                    $row->removal->soldPrice,
                    $row->removal->replacedBy,
                    $now,
                );
                $touched[$id] = $this->get($vehicle, $id);
                $fresh[$id] = true;
            }
        }

        foreach ($touched as $part) {
            // A part this record just made was announced as created, not as updated.
            $this->settle($vehicle, $part, !isset($fresh[$part->id]));
        }
    }

    /**
     * A record is being deleted: the fittings it made go (the parts stay),
     * and the removals it made are reopened. Run inside the record's
     * transaction, before it is deleted.
     *
     * @param 'maintenance'|'modification' $kind
     * @throws PartChangeRefused when a reopened fitting would overlap a later one
     */
    public function forgetRecord(Vehicle $vehicle, string $kind, int $recordId): void
    {
        $now = $this->clock->now();
        $touched = [];
        foreach ($this->byRecord($vehicle, $kind, $recordId) as ['part' => $part, 'fitting' => $fitting]) {
            if (self::fittedBy($fitting, $kind, $recordId)) {
                $this->parts->deleteFitting($fitting->id);
            } elseif (self::removedBy($fitting, $kind, $recordId)) {
                $this->parts->clearRemoved($fitting->id, $now);
            }
            $touched[$part->id] = $part;
        }
        foreach ($touched as $part) {
            $this->settle($vehicle, $part);
        }
    }

    /**
     * Reverting a modification (spec.md §7.39): take off the parts it fitted
     * that are still on, and fit again the stored parts it removed.
     *
     * @param array<int, PartRemoval> $remove by part id
     * @param list<int> $refit part ids
     * @throws PartChangeRefused
     */
    public function revert(
        Vehicle $vehicle,
        int $modificationId,
        DateTimeImmutable $on,
        ?string $km,
        array $remove,
        array $refit,
    ): void {
        $now = $this->clock->now();
        $touched = [];
        foreach ($this->byRecord($vehicle, self::MODIFICATION, $modificationId) as ['part' => $part, 'fitting' => $fitting]) {
            if (
                isset($remove[$part->id]) && $fitting->isOpen()
                && self::fittedBy($fitting, self::MODIFICATION, $modificationId)
            ) {
                $this->setRemoved($fitting, $on, $km, self::MODIFICATION, $modificationId, $remove[$part->id], true);
                $touched[$part->id] = $part;
            }
            if (
                in_array($part->id, $refit, true) && !isset($touched[$part->id])
                && self::removedBy($fitting, self::MODIFICATION, $modificationId)
                && PartTimeline::status($this->parts->fittingsOf($part->id)) === PartStatus::Stored
            ) {
                $this->parts->insertFitting($part->id, $on, $km, self::MODIFICATION, $modificationId, $now, true);
                $touched[$part->id] = $part;
            }
        }
        foreach ($touched as $part) {
            $this->settle($vehicle, $part);
        }
    }

    /**
     * Move the fittings and removals a revert made to its new date and
     * mileage (the revert was edited), or take them back ($undo).
     *
     * @throws PartChangeRefused
     */
    public function followRevert(
        Vehicle $vehicle,
        int $modificationId,
        ?DateTimeImmutable $on,
        ?string $km,
        bool $undo,
    ): void {
        $now = $this->clock->now();
        $made = $this->byRecord($vehicle, self::MODIFICATION, $modificationId);
        if ($undo || $on === null) {
            // A part the revert put back that has come off again since (sold, kept, returned)
            // has history of its own: taking the fitting back would erase it (#432).
            foreach ($made as ['part' => $part, 'fitting' => $fitting]) {
                if (
                    $fitting->fittedByRevert && self::fittedBy($fitting, self::MODIFICATION, $modificationId)
                    && $fitting->removedOn !== null
                ) {
                    throw new PartChangeRefused('modification.refused.undo_changed_since', ['part' => $part->label()]);
                }
            }
        }
        $touched = [];
        foreach ($made as ['part' => $part, 'fitting' => $fitting]) {
            if ($fitting->fittedByRevert && self::fittedBy($fitting, self::MODIFICATION, $modificationId)) {
                if ($undo || $on === null) {
                    $this->parts->deleteFitting($fitting->id);
                } else {
                    $this->parts->moveFitted($fitting->id, $on, $km, $now);
                }
                $touched[$part->id] = $part;
            } elseif ($fitting->removedByRevert && self::removedBy($fitting, self::MODIFICATION, $modificationId)) {
                if ($undo || $on === null) {
                    $this->parts->clearRemoved($fitting->id, $now);
                } else {
                    $this->setRemoved($fitting, $on, $km, self::MODIFICATION, $modificationId, self::removalOf($fitting), true);
                }
                $touched[$part->id] = $part;
            }
        }
        foreach ($touched as $part) {
            $this->settle($vehicle, $part);
        }
    }

    private function insertPart(Vehicle $vehicle, PartData $data): int
    {
        $by = $this->author->authorId() ?? $vehicle->userId;
        $id = $this->parts->insert($vehicle->id, $data, $this->clock->now(), $by);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryCreated, WebhookKind::Part, $id);

        return $id;
    }

    /**
     * @param 'maintenance'|'modification'|null $kind
     */
    private function setRemoved(
        PartFitting $fitting,
        DateTimeImmutable $on,
        ?string $km,
        ?string $kind,
        ?int $recordId,
        PartRemoval $removal,
        bool $byRevert = false,
    ): void {
        $this->parts->setRemoved(
            $fitting->id,
            $on,
            $km,
            $kind,
            $recordId,
            $removal->outcome,
            $removal->soldPrice,
            $removal->replacedBy,
            $this->clock->now(),
            $byRevert,
        );
    }

    /**
     * Check the part's fittings and store the status they give.
     *
     * @throws PartChangeRefused
     */
    private function settle(Vehicle $vehicle, Part $part, bool $notify = true): void
    {
        $fittings = $this->parts->fittingsOf($part->id);
        PartTimeline::check($part, $fittings);
        $status = PartTimeline::status($fittings);
        if ($status !== $part->status) {
            $this->parts->setStatus($part->id, $status);
        }
        // A fitting or removal, with or without a record, is an update of the part (#442).
        if ($notify) {
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Part, $part->id);
        }
    }

    /**
     * @throws PartChangeRefused
     */
    private function requireStatus(Part $part, PartStatus $expected): void
    {
        $status = PartTimeline::status($this->parts->fittingsOf($part->id));
        if ($status !== $expected) {
            throw new PartChangeRefused(
                $expected === PartStatus::Stored ? 'part.refused.not_stored' : 'part.refused.not_fitted',
                ['part' => $part->label()],
            );
        }
    }

    private static function fittedBy(PartFitting $fitting, string $kind, int $recordId): bool
    {
        return ($kind === self::MAINTENANCE ? $fitting->fittedMaintenanceId : $fitting->fittedModificationId) === $recordId;
    }

    private static function removedBy(PartFitting $fitting, string $kind, int $recordId): bool
    {
        return ($kind === self::MAINTENANCE ? $fitting->removedMaintenanceId : $fitting->removedModificationId) === $recordId;
    }

    private static function removalOf(PartFitting $fitting): PartRemoval
    {
        return new PartRemoval($fitting->outcome ?? RemovalOutcome::Kept, $fitting->soldPrice, $fitting->replacedByPartId);
    }

    private static function withOrigin(PartData $data, PartOrigin $origin): PartData
    {
        return new PartData(
            name: $data->name,
            brand: $data->brand,
            partNumber: $data->partNumber,
            serialNumber: $data->serialNumber,
            supplier: $data->supplier,
            boughtOn: $data->boughtOn,
            price: $data->price,
            condition: $data->condition,
            origin: $origin,
            warrantyMonths: $data->warrantyMonths,
            warrantyKm: $data->warrantyKm,
            warrantyFrom: $data->warrantyFrom,
            warrantyProvider: $data->warrantyProvider,
            warrantyTerms: $data->warrantyTerms,
            notes: $data->notes,
            warrantyReminder: $data->warrantyReminder,
        );
    }
}
