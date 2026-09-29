<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Keeps a vehicle's stored tyre state and tyre readings right (spec.md
 * §7.17): replays its changes and stores each tyre's status and position,
 * decides which reading a change owns, and keeps changes in step with the
 * service records they are linked to. Callers run it inside their own
 * transaction; a refusal (TyreChangeRefused) rolls everything back.
 *
 * It depends on nothing that writes service records, so MaintenanceService
 * can use it when a record is edited or deleted.
 */
final readonly class TyreSync
{
    /** A change's own reading is placed at local noon on its date, as a service record's is. */
    private const string READING_TIME = 'T12:00';

    public function __construct(
        private TyreRepository $tyres,
        private MaintenanceEntryRepository $entries,
        private OdometerService $odometer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Replay every change of the vehicle and store the result on its tyres.
     *
     * @throws TyreChangeRefused when the changes cannot be replayed in order
     */
    public function replay(Vehicle $vehicle): TyreReplayResult
    {
        $changes = $this->tyres->listChanges($vehicle->id);
        $result = TyreReplay::run($changes);
        if ($result instanceof TyreSequenceError) {
            throw $this->refusal($vehicle, $result, $changes);
        }

        $now = $this->clock->now();
        foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
            $state = $result->state($tyre->id);
            if ($state !== null && ($state->status !== $tyre->status || $state->position !== $tyre->position)) {
                $this->tyres->storeState($vehicle->id, $tyre->id, $state->status, $state->position, $now);
            }
        }

        return $result;
    }

    /**
     * Write, move or remove the change's own reading: none when its linked
     * service record has an odometer (that record's reading covers it), else
     * one at local noon on its date when it has an odometer.
     */
    public function recordReading(Vehicle $vehicle, int $changeId, TyreChangeData $data, DateTimeZone $zone): void
    {
        $record = $data->maintenanceEntryId === null ? null : $this->entries->find($vehicle->id, $data->maintenanceEntryId);
        if ($record?->data->odometerKm !== null || $data->odometerKm === null) {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Tyre, $changeId);

            return;
        }

        $at = LocalTime::toUtc($data->doneOn->format('Y-m-d') . self::READING_TIME, $zone)
            ?? DateTimeImmutable::createFromInterface($data->doneOn);
        $this->odometer->recordForEntry($vehicle, OdometerSource::Tyre, $changeId, $data->odometerKm, $at);
    }

    /**
     * A linked change takes its record's date, and its odometer when the
     * record has one (spec.md §7.17).
     */
    public static function aligned(TyreChangeData $data, MaintenanceEntry $record): TyreChangeData
    {
        return new TyreChangeData(
            doneOn: $record->data->performedOn,
            odometerKm: $record->data->odometerKm ?? $data->odometerKm,
            maintenanceEntryId: $record->id,
            note: $data->note,
        );
    }

    /**
     * A service record was saved: move its linked changes to its date and
     * odometer, fix their readings and replay (in the record's transaction).
     *
     * @throws TyreChangeRefused when the move breaks the sequence
     */
    public function followServiceRecord(Vehicle $vehicle, MaintenanceEntry $record, DateTimeZone $zone): void
    {
        $linked = $this->tyres->listChangesForEntries([$vehicle->id], [$record->id]);
        if ($linked === []) {
            return;
        }
        $now = $this->clock->now();
        foreach ($linked as $change) {
            $data = self::aligned($change->data, $record);
            $this->tyres->updateChange($vehicle->id, $change->id, $data, $now);
            $this->recordReading($vehicle, $change->id, $data, $zone);
        }
        $this->replay($vehicle);
    }

    /**
     * A service record is about to be deleted: unlink its changes, each of
     * which then writes its own reading (in the record's transaction).
     */
    public function releaseServiceRecord(Vehicle $vehicle, int $recordId, DateTimeZone $zone): void
    {
        $now = $this->clock->now();
        foreach ($this->tyres->listChangesForEntries([$vehicle->id], [$recordId]) as $change) {
            $data = new TyreChangeData($change->data->doneOn, $change->data->odometerKm, null, $change->data->note);
            $this->tyres->updateChange($vehicle->id, $change->id, $data, $now);
            $this->recordReading($vehicle, $change->id, $data, $zone);
        }
    }

    /**
     * The refusal for a failed replay, naming the tyre, the change's date and
     * the problem.
     *
     * @param list<TyreChange> $changes
     */
    private function refusal(Vehicle $vehicle, TyreSequenceError $error, array $changes): TyreChangeRefused
    {
        $date = null;
        foreach ($changes as $change) {
            if ($change->id === $error->changeId) {
                $date = $change->data->doneOn;
            }
        }
        $tyre = $this->tyres->findTyre($vehicle->id, $error->tyreId);

        return new TyreChangeRefused('tyre.error.sequence.' . $error->problem->value, [
            'tyre' => self::label($tyre),
            'position' => new TranslatableMessage('tyre.position.' . ($error->position->value ?? 'none')),
            'date' => $date ?? $this->clock->now(),
        ]);
    }

    /**
     * How a message names a tyre: brand and model, else its size, else "a tyre".
     */
    public static function label(?Tyre $tyre): string|TranslatableMessage
    {
        $name = $tyre?->data->name() ?? '';
        if ($name !== '') {
            return $name;
        }

        return $tyre?->data->size ?? new TranslatableMessage('tyre.unnamed');
    }
}
