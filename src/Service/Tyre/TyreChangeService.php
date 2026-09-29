<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\TyreRepository;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tyre changes (spec.md §7.17): the only way a tyre moves, and where tread
 * depths are recorded. One method per kind checks the choices against the
 * tyres as they are, then, in one transaction: creates any new tyres and
 * set, writes the change and its lines (with any depths), writes or links
 * the service record that carries the cost, writes the change's reading,
 * and replays the vehicle's changes into the stored tyre state. A refusal
 * (TyreChangeRefused) rolls everything back.
 *
 * Callers pass a Vehicle already resolved for the signed-in owner, the
 * owner's time zone (readings at local noon) and locale (the generated
 * service record title).
 */
final readonly class TyreChangeService
{
    public function __construct(
        private TyreRepository $tyres,
        private TyreSync $sync,
        private MaintenanceService $maintenance,
        private OdometerService $odometer,
        private Transaction $transaction,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TyreChangeNotFound
     */
    public function get(Vehicle $vehicle, int $id): TyreChange
    {
        return $this->tyres->findChange($vehicle->id, $id)
            ?? throw new TyreChangeNotFound(sprintf('Tyre change %d not found.', $id));
    }

    /**
     * Save a parsed change form of any kind.
     *
     * @throws TyreChangeRefused
     */
    public function record(Vehicle $vehicle, TyreChangeInput $input, DateTimeZone $zone, string $locale): TyreChange
    {
        $data = $input->data;
        $cost = $input->cost;

        return match ($input->kind) {
            TyreChangeKind::Existing => $this->existing($vehicle, $data, $input->new, $zone, $locale),
            TyreChangeKind::Fit => $this->fit($vehicle, $data, $input->new, $input->replaced, $cost, $zone, $locale),
            TyreChangeKind::Swap => $this->swap(
                $vehicle,
                $data,
                $input->into,
                $input->positions,
                $cost,
                $zone,
                $locale,
                $input->depths,
            ),
            TyreChangeKind::Rotate => $this->rotate($vehicle, $data, $input->positions, $zone, $locale),
            TyreChangeKind::Repair => $this->repair($vehicle, $data, array_keys($input->removed), $cost, $zone, $locale),
            TyreChangeKind::Remove => $this->remove(
                $vehicle,
                $data,
                $input->removed,
                $input->into,
                $cost,
                $zone,
                $locale,
                $input->depths,
            ),
            TyreChangeKind::Check => $this->check($vehicle, $data, $input->depths, $zone, $locale),
        };
    }

    /**
     * Tyres already on the vehicle: how an owner starts.
     *
     * @param list<NewTyre> $new
     * @throws TyreChangeRefused
     */
    public function existing(Vehicle $vehicle, TyreChangeData $data, array $new, DateTimeZone $zone, string $locale): TyreChange
    {
        $fitted = $this->fittedByPosition($vehicle);
        foreach ($new as $tyre) {
            $this->assertPosition($vehicle, $tyre->position);
            if (isset($fitted[$tyre->position->value])) {
                throw new TyreChangeRefused('tyre.error.position_taken', [
                    'position' => self::position($tyre->position),
                ], 'positions');
            }
        }

        return $this->save($vehicle, TyreChangeKind::Existing, $data, $new, [], null, $zone, $locale);
    }

    /**
     * Fit new tyres. A tyre already at a chosen position must be dealt with:
     * $replaced maps its position to a retire reason, or null to keep it in
     * storage.
     *
     * @param list<NewTyre> $new
     * @param array<string, ?TyreRetireReason> $replaced position code → reason (null = keep in storage)
     * @throws TyreChangeRefused
     */
    public function fit(
        Vehicle $vehicle,
        TyreChangeData $data,
        array $new,
        array $replaced,
        ?TyreCost $cost,
        DateTimeZone $zone,
        string $locale,
    ): TyreChange {
        $fitted = $this->fittedByPosition($vehicle);
        $lines = [];
        $reasons = [];
        foreach ($new as $tyre) {
            $this->assertPosition($vehicle, $tyre->position);
            $there = $fitted[$tyre->position->value] ?? null;
            if ($there === null) {
                continue;
            }
            if (!array_key_exists($tyre->position->value, $replaced)) {
                throw new TyreChangeRefused('tyre.error.replace_missing', [
                    'position' => self::position($tyre->position),
                ], 'replace_' . $tyre->position->value);
            }
            $reason = $replaced[$tyre->position->value];
            $action = $reason === null ? TyreLineAction::Off : TyreLineAction::Retire;
            $lines[] = new TyreChangeLine($there->id, $action, $tyre->position);
            if ($reason !== null) {
                $reasons[$there->id] = $reason;
            }
        }

        return $this->save($vehicle, TyreChangeKind::Fit, $data, $new, $lines, $cost, $zone, $locale, reasons: $reasons);
    }

    /**
     * Swap set: every fitted road tyre off (into $into), and the stored tyres
     * in $on fitted at their positions. The spare is left alone.
     *
     * @param array<int, TyrePosition> $on stored tyre id → position
     * @param array<int, string> $depths tyre id → depth measured, mm (tyres coming off or going on)
     * @throws TyreChangeRefused
     */
    public function swap(
        Vehicle $vehicle,
        TyreChangeData $data,
        SetChoice $into,
        array $on,
        ?TyreCost $cost,
        DateTimeZone $zone,
        string $locale,
        array $depths = [],
    ): TyreChange {
        $lines = [];
        $off = [];
        foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
            if ($tyre->isFitted() && $tyre->position?->isRolling() === true) {
                $lines[] = new TyreChangeLine($tyre->id, TyreLineAction::Off, $tyre->position);
                $off[] = $tyre->id;
            }
        }
        $taken = [];
        foreach ($on as $tyreId => $position) {
            $tyre = $this->tyre($vehicle, $tyreId);
            $this->assertPosition($vehicle, $position);
            if (!$tyre->isStored()) {
                throw new TyreChangeRefused('tyre.error.not_stored', ['tyre' => TyreSync::label($tyre)], 'on');
            }
            if (!$position->isRolling() || isset($taken[$position->value])) {
                throw new TyreChangeRefused('tyre.error.swap_positions', [], 'on');
            }
            $taken[$position->value] = true;
            $lines[] = new TyreChangeLine($tyreId, TyreLineAction::On, $position);
        }
        if ($lines === []) {
            throw new TyreChangeRefused('tyre.error.nothing', [], 'on');
        }

        $lines = self::measured($lines, $depths);

        return $this->save($vehicle, TyreChangeKind::Swap, $data, [], $lines, $cost, $zone, $locale, $into, $off);
    }

    /**
     * Rotate: a new position for every fitted tyre, a permutation of the
     * fitted positions (the spare may join it).
     *
     * @param array<int, TyrePosition> $positions fitted tyre id → new position
     * @throws TyreChangeRefused
     */
    public function rotate(
        Vehicle $vehicle,
        TyreChangeData $data,
        array $positions,
        DateTimeZone $zone,
        string $locale,
    ): TyreChange {
        $fitted = $this->fittedByPosition($vehicle);
        $before = array_keys($fitted);
        $after = [];
        $lines = [];
        foreach ($fitted as $tyre) {
            $position = $positions[$tyre->id] ?? $tyre->position;
            assert($position instanceof TyrePosition);
            $after[] = $position->value;
            if ($position !== $tyre->position) {
                $lines[] = new TyreChangeLine($tyre->id, TyreLineAction::Move, $position);
            }
        }
        sort($before);
        sort($after);
        if ($before !== $after) {
            throw new TyreChangeRefused('tyre.error.not_permutation', [], 'positions');
        }
        if ($lines === []) {
            throw new TyreChangeRefused('tyre.error.nothing_moved', [], 'positions');
        }

        return $this->save($vehicle, TyreChangeKind::Rotate, $data, [], $lines, null, $zone, $locale);
    }

    /**
     * Repair fitted tyres (a puncture and the like); nothing moves.
     *
     * @param list<int> $tyreIds
     * @throws TyreChangeRefused
     */
    public function repair(
        Vehicle $vehicle,
        TyreChangeData $data,
        array $tyreIds,
        ?TyreCost $cost,
        DateTimeZone $zone,
        string $locale,
    ): TyreChange {
        $lines = [];
        foreach (array_unique($tyreIds) as $id) {
            $tyre = $this->tyre($vehicle, $id);
            if (!$tyre->isFitted()) {
                throw new TyreChangeRefused('tyre.error.not_fitted', ['tyre' => TyreSync::label($tyre)], 'tyres');
            }
            $lines[] = new TyreChangeLine($id, TyreLineAction::Repair, $tyre->position);
        }
        if ($lines === []) {
            throw new TyreChangeRefused('tyre.error.nothing', [], 'tyres');
        }

        return $this->save($vehicle, TyreChangeKind::Repair, $data, [], $lines, $cost, $zone, $locale);
    }

    /**
     * Remove fitted tyres: into storage (optionally into a set) or retired.
     *
     * @param array<int, ?TyreRetireReason> $tyres fitted tyre id → reason (null = into storage)
     * @param array<int, string> $depths tyre id → depth measured as it came off, mm
     * @throws TyreChangeRefused
     */
    public function remove(
        Vehicle $vehicle,
        TyreChangeData $data,
        array $tyres,
        SetChoice $into,
        ?TyreCost $cost,
        DateTimeZone $zone,
        string $locale,
        array $depths = [],
    ): TyreChange {
        $lines = [];
        $reasons = [];
        $stored = [];
        foreach ($tyres as $id => $reason) {
            $tyre = $this->tyre($vehicle, $id);
            if (!$tyre->isFitted()) {
                throw new TyreChangeRefused('tyre.error.not_fitted', ['tyre' => TyreSync::label($tyre)], 'tyres');
            }
            $lines[] = new TyreChangeLine($id, $reason === null ? TyreLineAction::Off : TyreLineAction::Retire, $tyre->position);
            if ($reason === null) {
                $stored[] = $id;
            } else {
                $reasons[$id] = $reason;
            }
        }
        if ($lines === []) {
            throw new TyreChangeRefused('tyre.error.nothing', [], 'tyres');
        }

        return $this->save(
            $vehicle,
            TyreChangeKind::Remove,
            $data,
            [],
            self::measured($lines, $depths),
            $cost,
            $zone,
            $locale,
            $into,
            $stored,
            $reasons,
        );
    }

    /**
     * Check tread: a depth for one or more fitted tyres; nothing moves, and
     * no service record is written or linked.
     *
     * @param array<int, string> $depths fitted tyre id → depth, mm
     * @throws TyreChangeRefused
     */
    public function check(
        Vehicle $vehicle,
        TyreChangeData $data,
        array $depths,
        DateTimeZone $zone,
        string $locale,
    ): TyreChange {
        $lines = [];
        foreach ($depths as $id => $depth) {
            $tyre = $this->tyre($vehicle, $id);
            if (!$tyre->isFitted()) {
                throw new TyreChangeRefused('tyre.error.not_fitted', ['tyre' => TyreSync::label($tyre)], 'depths');
            }
            $lines[] = new TyreChangeLine($id, TyreLineAction::Measure, $tyre->position, $depth);
        }
        if ($lines === []) {
            throw new TyreChangeRefused('tyre.error.nothing_measured', [], 'depths');
        }
        $data = new TyreChangeData($data->doneOn, $data->odometerKm, null, $data->note);

        return $this->save($vehicle, TyreChangeKind::Check, $data, [], $lines, null, $zone, $locale);
    }

    /**
     * The depths just saved on a change that are more than
     * TyreWear::DEEPER_MM deeper than the same tyre's previous measurement
     * (the change is saved all the same; spec.md §7.17).
     *
     * @return list<DeeperReading>
     */
    public function deeperReadings(Vehicle $vehicle, TyreChange $change): array
    {
        $result = TyreReplay::run($this->tyres->listChanges($vehicle->id));
        if (!$result instanceof TyreReplayResult) {
            return [];
        }
        $deeper = [];
        foreach ($change->lines as $line) {
            if ($line->treadMm === null) {
                continue;
            }
            $previous = TyreWear::deeperThan($result->measurements($line->tyreId), $change->id);
            $tyre = $previous === null ? null : $this->tyres->findTyre($vehicle->id, $line->tyreId);
            if ($previous !== null && $tyre !== null) {
                $deeper[] = new DeeperReading($tyre, $line->treadMm, $previous);
            }
        }

        return $deeper;
    }

    /**
     * @param list<TyreChangeLine> $lines
     * @param array<int, string> $depths tyre id → mm
     * @return list<TyreChangeLine>
     */
    private static function measured(array $lines, array $depths): array
    {
        return array_map(
            static fn (TyreChangeLine $line): TyreChangeLine => $line->withTread($depths[$line->tyreId] ?? null),
            $lines,
        );
    }

    /**
     * Edit a change's date, odometer, note and service record link (its
     * lines are fixed), then replay.
     *
     * @throws TyreChangeRefused
     */
    public function update(Vehicle $vehicle, TyreChange $change, TyreChangeData $data, DateTimeZone $zone): TyreChange
    {
        $this->transaction->run(function () use ($vehicle, $change, $data, $zone): void {
            $data = $this->linked($vehicle, $data, $zone);
            $this->assertOdometer($change->kind, $data);
            $this->tyres->updateChange($vehicle->id, $change->id, $data, $this->clock->now());
            $this->sync->recordReading($vehicle, $change->id, $data, $zone);
            $this->sync->replay($vehicle);
        });

        return $this->get($vehicle, $change->id);
    }

    /**
     * Delete a change with its lines and reading. A tyre left with no lines
     * (one it created) is deleted with it; a linked service record is kept.
     *
     * @throws TyreChangeRefused when later changes depend on it
     */
    public function delete(Vehicle $vehicle, TyreChange $change): void
    {
        $this->transaction->run(function () use ($vehicle, $change): void {
            $this->odometer->forgetEntry($vehicle, OdometerSource::Tyre, $change->id);
            $this->tyres->deleteChange($vehicle->id, $change->id);
            foreach ($this->createdBy($vehicle, $change) as $tyre) {
                $this->tyres->deleteTyre($vehicle->id, $tyre->id);
            }
            $this->sync->replay($vehicle);
        });
    }

    /**
     * The tyres deleting $change would delete: those whose only lines are in it.
     *
     * @return list<Tyre>
     */
    public function createdBy(Vehicle $vehicle, TyreChange $change): array
    {
        $elsewhere = [];
        foreach ($this->tyres->listChanges($vehicle->id) as $other) {
            if ($other->id !== $change->id) {
                foreach ($other->tyreIds() as $id) {
                    $elsewhere[$id] = true;
                }
            }
        }
        $orphans = [];
        foreach ($change->tyreIds() as $id) {
            $tyre = $this->tyres->findTyre($vehicle->id, $id);
            if ($tyre !== null && !isset($elsewhere[$id])) {
                $orphans[] = $tyre;
            }
        }

        return $orphans;
    }

    /**
     * Delete a tyre with its lines. A change left with no lines is deleted
     * with its reading (a linked service record is kept); then the replay runs.
     */
    public function deleteTyre(Vehicle $vehicle, Tyre $tyre): void
    {
        $this->transaction->run(function () use ($vehicle, $tyre): void {
            $this->tyres->deleteTyre($vehicle->id, $tyre->id);
            foreach ($this->tyres->listChanges($vehicle->id) as $change) {
                if ($change->lines === []) {
                    $this->odometer->forgetEntry($vehicle, OdometerSource::Tyre, $change->id);
                    $this->tyres->deleteChange($vehicle->id, $change->id);
                }
            }
            $this->sync->replay($vehicle);
        });
    }

    /**
     * @param list<NewTyre> $new tyres to create and put on
     * @param list<TyreChangeLine> $lines lines for tyres that exist already
     * @param list<int> $intoSet tyres that go into $into
     * @param array<int, TyreRetireReason> $reasons retired tyre → why
     */
    private function save(
        Vehicle $vehicle,
        TyreChangeKind $kind,
        TyreChangeData $data,
        array $new,
        array $lines,
        ?TyreCost $cost,
        DateTimeZone $zone,
        string $locale,
        SetChoice $into = new SetChoice(),
        array $intoSet = [],
        array $reasons = [],
    ): TyreChange {
        $id = $this->transaction->run(function () use (
            $vehicle,
            $kind,
            $data,
            $new,
            $lines,
            $cost,
            $zone,
            $locale,
            $into,
            $intoSet,
            $reasons,
        ): int {
            $now = $this->clock->now();
            foreach ($new as $tyre) {
                $lines[] = new TyreChangeLine(
                    $this->tyres->insertTyre($vehicle->id, $tyre->data, $now),
                    TyreLineAction::On,
                    $tyre->position,
                    $tyre->treadMm,
                );
            }
            $data = $cost === null ? $this->linked($vehicle, $data, $zone) : $data;
            $this->assertOdometer($kind, $data);
            $id = $this->tyres->insertChange($vehicle->id, $kind, $data, $lines, $now);

            $setId = $into->setId ?? ($into->newSet === null ? null : $this->tyres->insertSet($vehicle->id, $into->newSet, $now));
            if ($setId !== null) {
                foreach ($intoSet as $tyreId) {
                    $this->tyres->setTyreSet($vehicle->id, $tyreId, $setId);
                }
            }
            foreach ($reasons as $tyreId => $reason) {
                $this->tyres->setRetiredReason($vehicle->id, $tyreId, $reason);
            }

            if ($cost !== null) {
                $data = $this->withServiceRecord($vehicle, $this->get($vehicle, $id), $data, $cost, $zone, $locale);
                $this->tyres->updateChange($vehicle->id, $id, $data, $now);
            }
            $this->sync->recordReading($vehicle, $id, $data, $zone);
            $this->sync->replay($vehicle);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    /**
     * Write the `tyres` service record that carries the cost, dated and
     * odometered as the change, titled in the owner's language, and link it.
     */
    private function withServiceRecord(
        Vehicle $vehicle,
        TyreChange $change,
        TyreChangeData $data,
        TyreCost $cost,
        DateTimeZone $zone,
        string $locale,
    ): TyreChangeData {
        $tyres = [];
        foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
            $tyres[$tyre->id] = $tyre;
        }
        $sets = [];
        foreach ($this->tyres->listSets($vehicle->id) as $set) {
            $sets[$set->id] = $set;
        }
        $title = mb_substr(
            TyreSummary::title($change, $tyres, $sets)->trans($this->translator, $locale),
            0,
            MaintenanceEntryForm::TITLE_MAX,
        );

        $record = $this->maintenance->create($vehicle, new MaintenanceEntryData(
            performedOn: $data->doneOn,
            category: MaintenanceCategory::Tyres,
            title: $title,
            cost: $cost->cost,
            odometerKm: $data->odometerKm,
            vendor: $cost->vendor,
        ), $zone);

        return new TyreChangeData($data->doneOn, $data->odometerKm, $record->id, $data->note);
    }

    /**
     * A change linked to an existing service record takes the record's date,
     * and its odometer when it has one; a record without one takes the
     * change's (spec.md §7.17).
     */
    private function linked(Vehicle $vehicle, TyreChangeData $data, DateTimeZone $zone): TyreChangeData
    {
        if ($data->maintenanceEntryId === null) {
            return $data;
        }
        $record = $this->maintenance->find($vehicle, $data->maintenanceEntryId);
        if ($record === null) {
            return new TyreChangeData($data->doneOn, $data->odometerKm, null, $data->note);
        }
        if ($record->data->odometerKm === null && $data->odometerKm !== null) {
            $record = $this->maintenance->update($vehicle, $record, new MaintenanceEntryData(
                performedOn: $record->data->performedOn,
                category: $record->data->category,
                title: $record->data->title,
                cost: $record->data->cost,
                odometerKm: $data->odometerKm,
                vendor: $record->data->vendor,
                description: $record->data->description,
                scheduleId: $record->data->scheduleId,
            ), $zone);
        }

        return TyreSync::aligned($data, $record);
    }

    /**
     * Every kind but a repair needs an odometer: its own, or (once aligned)
     * its linked record's.
     *
     * @throws TyreChangeRefused
     */
    private function assertOdometer(TyreChangeKind $kind, TyreChangeData $data): void
    {
        if ($kind->requiresOdometer() && $data->odometerKm === null) {
            throw new TyreChangeRefused('tyre.error.odometer_required', [], 'odometer');
        }
    }

    /**
     * @throws TyreChangeRefused
     */
    private function assertPosition(Vehicle $vehicle, TyrePosition $position): void
    {
        if (!in_array($position, $vehicle->data->type->tyrePositions(), true)) {
            throw new TyreChangeRefused('tyre.error.position_invalid', ['position' => self::position($position)], 'positions');
        }
    }

    /**
     * @throws TyreChangeRefused
     */
    private function tyre(Vehicle $vehicle, int $id): Tyre
    {
        return $this->tyres->findTyre($vehicle->id, $id) ?? throw new TyreChangeRefused('tyre.error.unknown_tyre');
    }

    /**
     * @return array<string, Tyre> position code → the tyre fitted there
     */
    private function fittedByPosition(Vehicle $vehicle): array
    {
        $fitted = [];
        foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
            if ($tyre->isFitted() && $tyre->position !== null) {
                $fitted[$tyre->position->value] = $tyre;
            }
        }
        $order = array_map(static fn (TyrePosition $p): string => $p->value, TyrePosition::cases());
        uksort($fitted, static fn (string $a, string $b): int => array_search($a, $order, true)
            <=> array_search($b, $order, true));

        return $fitted;
    }

    private static function position(TyrePosition $position): TranslatableMessage
    {
        return new TranslatableMessage('tyre.position.' . $position->value);
    }
}
