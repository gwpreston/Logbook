<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;

/**
 * Fill-ups (spec.md §7.3). Saving one also writes its odometer reading in the
 * same transaction, so mileage stays a single series; the derived figures
 * are recomputed from the full history on every read (FuelEconomy).
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class FuelService
{
    public function __construct(
        private FuelEntryRepository $entries,
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    public function history(Vehicle $vehicle): FuelHistory
    {
        return FuelEconomy::analyse($this->entries->listForVehicle($vehicle->id));
    }

    /**
     * @throws FuelEntryNotFound
     */
    public function get(Vehicle $vehicle, int $id): FuelEntry
    {
        return $this->entries->find($vehicle->id, $id)
            ?? throw new FuelEntryNotFound(sprintf('Fuel entry %d not found.', $id));
    }

    public function create(Vehicle $vehicle, FuelEntryData $data): FuelEntry
    {
        $id = $this->transaction->run(function () use ($vehicle, $data): int {
            $id = $this->entries->insert($vehicle->id, $data, $this->clock->now());
            $this->odometer->recordForEntry($vehicle, OdometerSource::Fuel, $id, $data->odometerKm, $data->filledAt);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(Vehicle $vehicle, FuelEntry $entry, FuelEntryData $data): FuelEntry
    {
        $this->transaction->run(function () use ($vehicle, $entry, $data): void {
            $this->entries->update($vehicle->id, $entry->id, $data, $this->clock->now());
            $this->odometer->recordForEntry($vehicle, OdometerSource::Fuel, $entry->id, $data->odometerKm, $data->filledAt);
        });

        return $this->get($vehicle, $entry->id);
    }

    /**
     * Delete a fill-up with its odometer reading and attachments.
     */
    public function delete(Vehicle $vehicle, FuelEntry $entry): void
    {
        // The foreign key cascades too; removing it explicitly keeps the
        // series right even where cascades are off.
        $this->transaction->run(function () use ($vehicle, $entry): void {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Fuel, $entry->id);
            $this->entries->delete($vehicle->id, $entry->id);
        });
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Fuel, $entry->id);
    }

    /**
     * Plausibility warning for the fill-up's odometer within the whole series.
     */
    public function odometerWarning(Vehicle $vehicle, FuelEntry $entry): ?OdometerWarning
    {
        return $this->odometer->warningForEntry($vehicle, OdometerSource::Fuel, $entry->id);
    }
}
