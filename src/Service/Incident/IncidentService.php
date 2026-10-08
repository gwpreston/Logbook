<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Service\Webhook\WebhookEvents;
use Logbook\Domain\Tyre\TyreChange;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Incidents and their links (spec.md §7.29): logging and editing one with
 * its odometer reading and photos, the insurer default, closing, and
 * linking the records it caused.
 */
final readonly class IncidentService
{
    /** A dated-only incident's reading is placed at local noon, as documents' are. */
    private const string READING_TIME = 'T12:00';

    public function __construct(
        private IncidentRepository $incidents,
        private ComplianceDocumentRepository $documents,
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private CostLedger $ledger,
        private VehicleService $vehicles,
        private AccessContext $author,
        private Transaction $transaction,
        private ClockInterface $clock,
        private WebhookEvents $webhooks,
    ) {
    }

    /**
     * @return list<Incident> open ones first, then newest first
     */
    public function list(Vehicle $vehicle): array
    {
        return $this->incidents->listForVehicle($vehicle->id);
    }

    /**
     * @throws IncidentNotFound
     */
    public function get(Vehicle $vehicle, int $id): Incident
    {
        return $this->incidents->find($vehicle->id, $id)
            ?? throw new IncidentNotFound(sprintf('Incident %d not found.', $id));
    }

    /**
     * @param string|null $odometerKm the odometer at the time, canonical km; writes an `incident` reading
     * @param DateTimeZone $zone the owner's zone: today, and where a dated-only reading's noon is
     */
    public function create(
        Vehicle $vehicle,
        IncidentData $data,
        ?string $odometerKm,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
    ): Incident {
        $data = $this->settled($data, null, $zone);
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data, $odometerKm, $zone): int {
            $by = $this->author->authorId() ?? $vehicle->userId;
            $id = $this->incidents->insert($vehicle->id, $data, $this->clock->now(), $by);
            $this->recordOdometer($vehicle, $id, $data, $odometerKm, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Incident, $id, $stored);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryCreated, WebhookKind::Incident, $id);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(
        Vehicle $vehicle,
        Incident $incident,
        IncidentData $data,
        ?string $odometerKm,
        DateTimeZone $zone,
        PendingUploads $files = new PendingUploads(),
    ): Incident {
        $data = $this->settled($data, $incident, $zone);
        $id = $incident->id;
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $id, $data, $odometerKm, $zone): void {
            $this->incidents->update($vehicle->id, $id, $data, $this->clock->now());
            $this->recordOdometer($vehicle, $id, $data, $odometerKm, $zone);
            $this->attachments->record($vehicle, AttachmentOwner::Incident, $id, $stored);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Incident, $id);
        });

        return $this->get($vehicle, $incident->id);
    }

    /**
     * Delete an incident with its reading and photos. Its linked records are
     * unlinked, never deleted.
     */
    public function delete(Vehicle $vehicle, Incident $incident): void
    {
        $this->transaction->run(function () use ($vehicle, $incident): void {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Incident, $incident->id);
            $this->incidents->delete($vehicle->id, $incident->id);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryDeleted, WebhookKind::Incident, $incident->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Incident, $incident->id);
    }

    /**
     * The incident's odometer, canonical km, for the edit form.
     */
    public function odometerOf(Vehicle $vehicle, Incident $incident): ?string
    {
        return $this->odometer->readingForEntry($vehicle, OdometerSource::Incident, $incident->id)?->readingKm;
    }

    /**
     * The `insurance` document current on a date: started on or before it
     * and not expired before it (the latest start wins). The form's insurer
     * default (spec.md §7.29).
     */
    public function policyOn(Vehicle $vehicle, DateTimeImmutable $date): ?ComplianceDocument
    {
        $current = null;
        foreach ($this->documents->listForVehicle($vehicle->id) as $document) {
            $doc = $document->data;
            if (
                $doc->type !== ComplianceType::Insurance
                || $doc->startOn === null || $doc->startOn > $date
                || ($doc->expiryOn !== null && $doc->expiryOn < $date)
            ) {
                continue;
            }
            if ($current === null || $doc->startOn >= $current->data->startOn) {
                $current = $document;
            }
        }

        return $current;
    }

    /**
     * The incident's costs from the ledger, as Reports counts them.
     */
    public function costs(User $user, Vehicle $vehicle, Incident $incident): IncidentCosts
    {
        return IncidentCosts::of(
            $incident->id,
            $this->ledger->items($user, [$vehicle]),
            $incident->data->claim->payout,
            $this->vehicles->currencyFor($user, $vehicle),
        );
    }

    /**
     * Every listed incident's costs, from one read of the ledger.
     *
     * @param list<Incident> $incidents of this vehicle
     * @return array<int, IncidentCosts> by incident id
     */
    public function costsFor(User $user, Vehicle $vehicle, array $incidents): array
    {
        if ($incidents === []) {
            return [];
        }
        $items = $this->ledger->items($user, [$vehicle]);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $costs = [];
        foreach ($incidents as $incident) {
            $costs[$incident->id] = IncidentCosts::of($incident->id, $items, $incident->data->claim->payout, $currency);
        }

        return $costs;
    }

    /**
     * Link a record of the vehicle to the incident, or unlink it (null). A
     * tyre change linked to a service record follows its record (#103), so
     * it is linked through the record instead.
     */
    public function link(Vehicle $vehicle, LinkKind $kind, int $recordId, ?Incident $incident): void
    {
        $this->incidents->setLink($kind, $vehicle->id, $recordId, $incident?->id);
    }

    /**
     * A tyre change linked to a service record takes the record's incident
     * (#103), e.g. after it was linked to another record.
     */
    public function followRecord(Vehicle $vehicle, TyreChange $change): void
    {
        $record = $change->data->maintenanceEntryId;
        if ($record === null) {
            return;
        }
        $this->incidents->setLink(
            LinkKind::Tyre,
            $vehicle->id,
            $change->id,
            $this->incidents->linkOf(LinkKind::Maintenance, $vehicle->id, $record),
        );
    }

    /**
     * The incident a record is linked to, or null.
     */
    public function linkOf(Vehicle $vehicle, LinkKind $kind, int $recordId): ?int
    {
        return $this->incidents->linkOf($kind, $vehicle->id, $recordId);
    }

    /**
     * The ids of the records linked to each incident of the vehicle.
     *
     * @return array<int, array<value-of<LinkKind>, list<int>>> by incident id
     */
    public function links(Vehicle $vehicle): array
    {
        return $this->incidents->linksForVehicle($vehicle->id);
    }

    /**
     * Closing sets closed_on to today unless given; reopening clears it. A
     * changed claim status moves the latest update to today unless that was
     * changed too (spec.md §7.29 *Form*).
     */
    private function settled(IncidentData $data, ?Incident $before, DateTimeZone $zone): IncidentData
    {
        $today = LocalTime::today($this->clock, $zone);
        $closedOn = match ($data->status) {
            IncidentStatus::Closed => $data->closedOn ?? $today,
            IncidentStatus::Open => null,
        };
        $claim = $data->claim;
        $previous = $before?->data->claim;
        if (
            $previous !== null
            && $claim->status !== $previous->status
            && $claim->updatedOn == $previous->updatedOn
        ) {
            $claim = new Claim(
                status: $claim->status,
                insurer: $claim->insurer,
                insuranceDocumentId: $claim->insuranceDocumentId,
                claimNumber: $claim->claimNumber,
                excess: $claim->excess,
                payout: $claim->payout,
                ncdAffected: $claim->ncdAffected,
                updatedOn: $today,
                repairEstimate: $claim->repairEstimate,
            );
        }

        return new IncidentData(
            occurredOn: $data->occurredOn,
            type: $data->type,
            occurredAtTime: $data->occurredAtTime,
            location: $data->location,
            fault: $data->fault,
            description: $data->description,
            damageAreas: $data->damageAreas,
            severity: $data->severity,
            driverUserId: $data->driverUserId,
            driverName: $data->driverName,
            otherPartyName: $data->otherPartyName,
            otherPartyRegistration: $data->otherPartyRegistration,
            otherPartyInsurer: $data->otherPartyInsurer,
            policeReference: $data->policeReference,
            status: $data->status,
            closedOn: $closedOn,
            writeOff: $data->writeOff,
            notes: $data->notes,
            claim: $claim,
        );
    }

    /**
     * Create, move or remove the incident's reading: at its local time, else noon.
     */
    private function recordOdometer(Vehicle $vehicle, int $id, IncidentData $data, ?string $odometerKm, DateTimeZone $zone): void
    {
        if ($odometerKm === null) {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Incident, $id);

            return;
        }

        $time = $data->occurredAtTime === null ? self::READING_TIME : 'T' . $data->occurredAtTime;
        $at = LocalTime::toUtc($data->occurredOn->format('Y-m-d') . $time, $zone)
            ?? DateTimeImmutable::createFromInterface($data->occurredOn);
        $this->odometer->recordForEntry($vehicle, OdometerSource::Incident, $id, $odometerKm, $at);
    }
}
