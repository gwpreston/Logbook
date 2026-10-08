<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Service\Webhook\WebhookEvents;
use Logbook\Service\Access\AccessContext;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreSync;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Service history (spec.md §7.4). Saving an entry, in one transaction:
 * writes its odometer reading (when it has an odometer) into the mileage
 * series, records its new attachments, and recomputes the schedule it
 * completes — both the old and the new one when an edit moves it. Tyre
 * changes linked to an entry follow its date and odometer, and write their
 * own readings when it is deleted (spec.md §7.17).
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class MaintenanceService
{
    /** A dated entry's odometer reading is placed at local noon on that day. */
    private const string READING_TIME = 'T12:00';

    public function __construct(
        private MaintenanceEntryRepository $entries,
        private ScheduleService $schedules,
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private Transaction $transaction,
        private ClockInterface $clock,
        private TyreSync $tyres,
        private AccessContext $author,
        private WebhookEvents $webhooks,
        private IssueService $issues,
    ) {
    }

    public function history(Vehicle $vehicle): MaintenanceHistory
    {
        return new MaintenanceHistory($this->entries->listForVehicle($vehicle->id));
    }

    /**
     * @throws MaintenanceEntryNotFound
     */
    public function get(Vehicle $vehicle, int $id): MaintenanceEntry
    {
        return $this->entries->find($vehicle->id, $id)
            ?? throw new MaintenanceEntryNotFound(sprintf('Maintenance entry %d not found.', $id));
    }

    public function find(Vehicle $vehicle, int $id): ?MaintenanceEntry
    {
        return $this->entries->find($vehicle->id, $id);
    }

    /**
     * @param DateTimeZone $zone the owner's zone: the entry's odometer
     *                           reading is recorded at noon on its date there
     * @param list<int>|null $fixes the issues it fixes (the *Fixes* checklist,
     *                              spec.md §7.37); null leaves them alone
     */
    public function create(
        Vehicle $vehicle,
        MaintenanceEntryData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
        ?array $fixes = null,
    ): MaintenanceEntry {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data, $zone, $fixes): int {
            $by = $this->author->authorId() ?? $vehicle->userId;
            $id = $this->entries->insert($vehicle->id, $data, $this->clock->now(), $by);
            $this->recordOdometer($vehicle, $id, $data, $zone);
            $this->recomputeSchedules($vehicle, $data->scheduleId);
            $this->attachments->record($vehicle, AttachmentOwner::Maintenance, $id, $stored);
            if ($fixes !== null) {
                $this->issues->setFixesOf($vehicle, $this->get($vehicle, $id), $fixes, $zone);
            }
            $this->webhooks->entry($vehicle, WebhookEvent::EntryCreated, WebhookKind::Maintenance, $id);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    /**
     * @param list<int>|null $fixes the issues it fixes; null leaves them alone
     * @throws TyreChangeRefused when a linked tyre change cannot move to the new date or odometer
     */
    public function update(
        Vehicle $vehicle,
        MaintenanceEntry $entry,
        MaintenanceEntryData $data,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
        ?array $fixes = null,
    ): MaintenanceEntry {
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $entry, $data, $zone, $fixes): void {
            $this->entries->update($vehicle->id, $entry->id, $data, $this->clock->now());
            $this->recordOdometer($vehicle, $entry->id, $data, $zone);
            $this->recomputeSchedules($vehicle, $entry->data->scheduleId, $data->scheduleId);
            $this->tyres->followServiceRecord($vehicle, $this->get($vehicle, $entry->id), $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Maintenance, $entry->id, $stored);
            // The issues it fixes follow its date, whether or not the checklist was sent.
            $fixes ??= $this->issues->fixedBy($entry->id);
            $this->issues->setFixesOf($vehicle, $this->get($vehicle, $entry->id), $fixes, $zone);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Maintenance, $entry->id);
        });

        return $this->get($vehicle, $entry->id);
    }

    /**
     * Delete an entry with its odometer reading and attachments; the schedule
     * it completed falls back to the previous entry (or its baseline). Tyre
     * changes linked to it are unlinked and write their own readings. The
     * issues it fixed lose the link, and with none left are reopened.
     *
     * @param DateTimeZone $zone the owner's zone, for those readings
     */
    public function delete(Vehicle $vehicle, MaintenanceEntry $entry, DateTimeZone $zone): void
    {
        $this->transaction->run(function () use ($vehicle, $entry, $zone): void {
            $this->tyres->releaseServiceRecord($vehicle, $entry->id, $zone);
            $this->issues->recordDeleted($vehicle, $entry->id, $zone);
            $this->odometer->forgetEntry($vehicle, OdometerSource::Maintenance, $entry->id);
            $this->entries->delete($vehicle->id, $entry->id);
            $this->recomputeSchedules($vehicle, $entry->data->scheduleId);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryDeleted, WebhookKind::Maintenance, $entry->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Maintenance, $entry->id);
    }

    /**
     * Plausibility warning for the entry's odometer within the whole series.
     */
    public function odometerWarning(Vehicle $vehicle, MaintenanceEntry $entry): ?OdometerWarning
    {
        return $this->odometer->warningForEntry($vehicle, OdometerSource::Maintenance, $entry->id);
    }

    private function recordOdometer(Vehicle $vehicle, int $entryId, MaintenanceEntryData $data, DateTimeZone $zone): void
    {
        $at = LocalTime::toUtc($data->performedOn->format('Y-m-d') . self::READING_TIME, $zone)
            ?? DateTimeImmutable::createFromInterface($data->performedOn);

        $this->odometer->recordForEntry($vehicle, OdometerSource::Maintenance, $entryId, $data->odometerKm, $at);
    }

    private function recomputeSchedules(Vehicle $vehicle, ?int ...$scheduleIds): void
    {
        foreach (array_unique(array_filter($scheduleIds, static fn (?int $id): bool => $id !== null)) as $id) {
            $this->schedules->recompute($vehicle, $id);
        }
    }
}
