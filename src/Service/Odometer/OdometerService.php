<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use Logbook\Support\Database\Transaction;
use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Service\Webhook\WebhookEvents;
use Logbook\Service\Access\AccessContext;
use DateTimeImmutable;
use LogicException;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * A vehicle's mileage as one coherent series (spec.md §7.2): manual readings
 * (and their attachments) are managed here directly; fill-ups, maintenance
 * entries and documents record theirs through recordForEntry(), so every
 * source lands in the same table and the same history.
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class OdometerService
{
    public function __construct(
        private OdometerReadingRepository $readings,
        private AttachmentService $attachments,
        private ClockInterface $clock,
        private AccessContext $author,
        private WebhookEvents $webhooks,
        private Transaction $transaction,
    ) {
    }

    public function history(Vehicle $vehicle): OdometerHistory
    {
        return new OdometerHistory($this->readings->listForVehicle($vehicle->id));
    }

    /**
     * @throws OdometerReadingNotFound
     */
    public function get(Vehicle $vehicle, int $id): OdometerReading
    {
        return $this->readings->find($vehicle->id, $id)
            ?? throw new OdometerReadingNotFound(sprintf('Odometer reading %d not found.', $id));
    }

    public function create(
        Vehicle $vehicle,
        OdometerReadingData $data,
        PendingUploads $files = new PendingUploads(),
    ): OdometerReading {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data): int {
            $now = $this->clock->now();
            $by = $this->author->authorId() ?? $vehicle->userId;
            $id = $this->readings->insert($vehicle->id, $data, OdometerSource::Manual, null, $now, $by);
            $this->attachments->record($vehicle, AttachmentOwner::Odometer, $id, $stored);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryCreated, WebhookKind::Odometer, $id);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(
        Vehicle $vehicle,
        OdometerReading $reading,
        OdometerReadingData $data,
        PendingUploads $files = new PendingUploads(),
    ): OdometerReading {
        self::assertManual($reading);
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $reading, $data): void {
            $this->readings->update($vehicle->id, $reading->id, $data, $this->clock->now());
            $this->attachments->record($vehicle, AttachmentOwner::Odometer, $reading->id, $stored);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Odometer, $reading->id);
        });

        return $this->get($vehicle, $reading->id);
    }

    /**
     * Delete a manual reading with its attachments.
     */
    public function delete(Vehicle $vehicle, OdometerReading $reading): void
    {
        self::assertManual($reading);
        $this->transaction->run(function () use ($vehicle, $reading): void {
            $this->readings->delete($vehicle->id, $reading->id);
            $this->webhooks->entry($vehicle, WebhookEvent::EntryDeleted, WebhookKind::Odometer, $reading->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Odometer, $reading->id);
    }

    /**
     * Create or move the reading that belongs to a fill-up or maintenance
     * entry; with no odometer ($km null) remove it. Call inside the entry's
     * transaction.
     */
    public function recordForEntry(
        Vehicle $vehicle,
        OdometerSource $source,
        int $entryId,
        ?string $km,
        DateTimeImmutable $at,
    ): void {
        self::assertOwned($source);
        if ($km === null) {
            $this->forgetEntry($vehicle, $source, $entryId);

            return;
        }

        $data = new OdometerReadingData($km, $at);
        $existing = $this->readings->findByEntry($vehicle->id, $source, $entryId);

        if ($existing === null) {
            $this->readings->insert($vehicle->id, $data, $source, $entryId, $this->clock->now());
        } else {
            $this->readings->update($vehicle->id, $existing->id, $data, $this->clock->now());
        }
    }

    /**
     * Whether the vehicle has a reading in [from, until) at this odometer
     * other than the one this entry owns (spec.md §7.37, #319).
     */
    public function hasOtherReadingAt(
        Vehicle $vehicle,
        string $km,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        OdometerSource $source,
        int $entryId,
    ): bool {
        foreach ($this->readings->listForVehicleBetween($vehicle->id, $from, $until) as $reading) {
            $own = $reading->source === $source && match ($source) {
                OdometerSource::Issue => $reading->issueId === $entryId,
                OdometerSource::IssueUpdate => $reading->issueUpdateId === $entryId,
                default => false,
            };
            if (!$own && Decimal::compare($reading->readingKm, $km) === 0) {
                return true;
            }
        }

        return false;
    }

    public function forgetEntry(Vehicle $vehicle, OdometerSource $source, int $entryId): void
    {
        self::assertOwned($source);
        $existing = $this->readings->findByEntry($vehicle->id, $source, $entryId);
        if ($existing !== null) {
            $this->readings->delete($vehicle->id, $existing->id);
        }
    }

    public function readingForEntry(Vehicle $vehicle, OdometerSource $source, int $entryId): ?OdometerReading
    {
        self::assertOwned($source);

        return $this->readings->findByEntry($vehicle->id, $source, $entryId);
    }

    /**
     * Plausibility warning for the reading an entry owns, if it has one.
     */
    public function warningForEntry(Vehicle $vehicle, OdometerSource $source, int $entryId): ?OdometerWarning
    {
        $reading = $this->readingForEntry($vehicle, $source, $entryId);

        return $reading === null ? null : $this->warningFor($vehicle, $reading->id);
    }

    /**
     * The vehicle's *Mileage when bought* (spec.md §6 OdometerReading), if
     * it has one.
     */
    public function purchaseReading(Vehicle $vehicle): ?OdometerReading
    {
        return $this->readings->findPurchase($vehicle->id);
    }

    /**
     * Create or move the vehicle's `purchase` reading; with no mileage ($km
     * null) remove it. Call inside the vehicle's transaction.
     */
    public function recordPurchase(Vehicle $vehicle, ?string $km, DateTimeImmutable $at): void
    {
        $existing = $this->readings->findPurchase($vehicle->id);
        if ($km === null) {
            if ($existing !== null) {
                $this->readings->delete($vehicle->id, $existing->id);
            }

            return;
        }

        $data = new OdometerReadingData($km, $at);
        if ($existing === null) {
            $this->readings->insert($vehicle->id, $data, OdometerSource::Purchase, null, $this->clock->now());
        } else {
            $this->readings->update($vehicle->id, $existing->id, $data, $this->clock->now());
        }
    }

    /**
     * Plausibility warning for the vehicle's `purchase` reading, if it has one.
     */
    public function purchaseWarning(Vehicle $vehicle): ?OdometerWarning
    {
        $reading = $this->purchaseReading($vehicle);

        return $reading === null ? null : $this->warningFor($vehicle, $reading->id);
    }

    /**
     * Plausibility warning for one reading within the vehicle's series.
     */
    public function warningFor(Vehicle $vehicle, int $readingId): ?OdometerWarning
    {
        return $this->history($vehicle)->warningFor($readingId);
    }

    private static function assertOwned(OdometerSource $source): void
    {
        if ($source === OdometerSource::Manual || $source === OdometerSource::Purchase) {
            throw new LogicException(sprintf('%s readings are not owned by an entry.', ucfirst($source->value)));
        }
    }

    private static function assertManual(OdometerReading $reading): void
    {
        if (!$reading->isManual()) {
            throw new LogicException('Only manual readings are edited directly; change the entry that owns it.');
        }
    }
}
