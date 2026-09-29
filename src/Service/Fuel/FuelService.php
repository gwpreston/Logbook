<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;

/**
 * Fill-ups (spec.md §7.3). Saving one also writes its odometer reading and
 * its attachments in the same transaction, so mileage stays a single series
 * and a failed save leaves no files behind; the derived figures
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
        return FuelEconomy::analyse($this->entries($vehicle));
    }

    /**
     * The economy checks of a history (spec.md §7.3). Kept apart from
     * history(): only the pages that show flags ask for them.
     */
    public function checks(FuelHistory $history): EconomyChecks
    {
        return EconomyCheck::of($history);
    }

    /**
     * *Looks right*: store the consumption the segment closed by this
     * fill-up measures now, so its flag stays hidden until the figure changes.
     *
     * @throws EconomyNotCheckable when it closes no checkable segment
     */
    public function confirmEconomy(Vehicle $vehicle, FuelEntry $entry): void
    {
        $segment = null;
        foreach ($this->history($vehicle)->fills as $fill) {
            if ($fill->entry->id === $entry->id) {
                $segment = $fill->segment;
            }
        }
        if ($segment === null || !EconomyCheck::isCheckable($segment)) {
            throw new EconomyNotCheckable(sprintf('Fuel entry %d closes no checkable segment.', $entry->id));
        }

        $this->entries->setEconomyConfirmed($vehicle->id, $entry->id, EconomyCheck::consumption($segment));
    }

    /**
     * *Undo* a confirmation: the flag (if any) shows again.
     */
    public function unconfirmEconomy(Vehicle $vehicle, FuelEntry $entry): void
    {
        $this->entries->setEconomyConfirmed($vehicle->id, $entry->id, null);
    }

    /**
     * @return list<FuelEntry> oldest first
     */
    public function entries(Vehicle $vehicle): array
    {
        return $this->entries->listForVehicle($vehicle->id);
    }

    /**
     * The *By grade* figures of each kind of energy with a graded fill-up
     * (spec.md §7.3); empty when no fill-up has a grade.
     *
     * @return array<string, GradeBreakdown> keyed by EnergyKind value
     */
    public function gradeBreakdowns(FuelHistory $history): array
    {
        $breakdowns = [];
        foreach (EnergyKind::cases() as $kind) {
            $breakdown = GradeStatistics::breakdown($history, $kind);
            if ($breakdown->hasGrades()) {
                $breakdowns[$kind->value] = $breakdown;
            }
        }

        return $breakdowns;
    }

    /**
     * @throws FuelEntryNotFound
     */
    public function get(Vehicle $vehicle, int $id): FuelEntry
    {
        return $this->entries->find($vehicle->id, $id)
            ?? throw new FuelEntryNotFound(sprintf('Fuel entry %d not found.', $id));
    }

    public function create(Vehicle $vehicle, FuelEntryData $data, PendingUploads $files = new PendingUploads()): FuelEntry
    {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data): int {
            $id = $this->entries->insert($vehicle->id, $data, $this->clock->now());
            $this->odometer->recordForEntry($vehicle, OdometerSource::Fuel, $id, $data->odometerKm, $data->filledAt);
            $this->attachments->record($vehicle, AttachmentOwner::Fuel, $id, $stored);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(
        Vehicle $vehicle,
        FuelEntry $entry,
        FuelEntryData $data,
        PendingUploads $files = new PendingUploads(),
    ): FuelEntry {
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $entry, $data): void {
            $this->entries->update($vehicle->id, $entry->id, $data, $this->clock->now());
            $this->odometer->recordForEntry($vehicle, OdometerSource::Fuel, $entry->id, $data->odometerKm, $data->filledAt);
            $this->attachments->record($vehicle, AttachmentOwner::Fuel, $entry->id, $stored);
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
