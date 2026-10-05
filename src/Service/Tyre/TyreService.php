<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * A vehicle's tyres and sets as shown (spec.md §7.17): what is fitted,
 * stored and retired, with distance (from the mileage series), age (from the
 * DOT date), cost per distance, tread and the wear estimate derived on every
 * read against the owner's thresholds, and the judgement the tyre reminder
 * shares; and the edits that do not move a tyre (a tyre's description, a
 * set's name and storage).
 *
 * Linked service records are read only while the maintenance module is on.
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class TyreService
{
    /** A change may link a `tyres` service record this many days either side of its date. */
    public const int LINK_WINDOW_DAYS = 30;

    public function __construct(
        private TyreRepository $tyres,
        private MaintenanceEntryRepository $entries,
        private OdometerService $odometer,
        private FeatureToggles $features,
        private ClockInterface $clock,
        private TyreSettingsStore $settings,
        private ReminderSettingsStore $reminderSettings,
    ) {
    }

    public function overview(Vehicle $vehicle, User $user): TyreOverview
    {
        $changes = $this->tyres->listChanges($vehicle->id);
        $sets = $this->tyres->listSets($vehicle->id);
        $views = $this->build($vehicle, $user, $this->tyres->listTyres($vehicle->id), $changes);
        $records = $this->records($vehicle);
        $tyresById = [];
        foreach ($views as $view) {
            $tyresById[$view->tyre->id] = $view->tyre;
        }
        $setsById = self::setsById($sets);

        $fitted = [];
        foreach ($vehicle->data->type->tyrePositions() as $position) {
            $fitted[$position->value] = null;
        }
        $bySet = [];
        $unset = [];
        $retired = [];
        foreach ($views as $view) {
            $tyre = $view->tyre;
            if ($tyre->isFitted() && $tyre->position !== null && array_key_exists($tyre->position->value, $fitted)) {
                $fitted[$tyre->position->value] = $view;
            } elseif ($tyre->isStored()) {
                if ($tyre->setId !== null && isset($setsById[$tyre->setId])) {
                    $bySet[$tyre->setId][] = $view;
                } else {
                    $unset[] = $view;
                }
            } elseif ($tyre->isRetired()) {
                $retired[] = $view;
            }
        }
        $stored = [];
        foreach ($sets as $set) {
            if (isset($bySet[$set->id])) {
                $stored[] = new TyreSetGroup($set, $bySet[$set->id]);
            }
        }
        if ($unset !== []) {
            $stored[] = new TyreSetGroup(null, $unset);
        }
        usort($retired, static fn (TyreView $a, TyreView $b): int => ($b->retiredOn <=> $a->retiredOn)
            ?: $b->tyre->id <=> $a->tyre->id);

        usort($changes, static fn (TyreChange $a, TyreChange $b): int => TyreChange::compare($b, $a));
        $unit = $user->preferences->depthUnit;
        $listed = array_map(static fn (TyreChange $change): TyreChangeView => new TyreChangeView(
            $change,
            TyreSummary::line($change, $tyresById, $setsById, $unit),
            $change->data->maintenanceEntryId === null ? null : ($records[$change->data->maintenanceEntryId] ?? null),
        ), $changes);

        $thresholds = $this->settings->thresholds($user->id);
        $type = $vehicle->data->type;

        return new TyreOverview(
            $fitted,
            $stored,
            $retired,
            $listed,
            $sets,
            $this->judge($vehicle, $user, $views),
            $thresholds->replaceAt($type, null),
            $thresholds->legalMinimum($type),
        );
    }

    /**
     * Every tyre of the vehicle with its figures, in the order added (CSV).
     *
     * @return list<TyreView>
     */
    public function views(Vehicle $vehicle, User $user): array
    {
        return $this->build($vehicle, $user, $this->tyres->listTyres($vehicle->id), $this->tyres->listChanges($vehicle->id));
    }

    /**
     * The fitted tyres with their figures (the print header, the overview
     * card), in position order.
     *
     * @return list<TyreView>
     */
    public function fitted(Vehicle $vehicle, User $user): array
    {
        return $this->overview($vehicle, $user)->fittedTyres();
    }

    /**
     * The vehicle's tyres judged against the owner's thresholds and schedule
     * lead times (spec.md §7.6): what the tyre reminder and the tab badge
     * show. Read for an active or archived vehicle alike.
     */
    public function verdict(Vehicle $vehicle, User $user): TyreVerdict
    {
        return $this->judge($vehicle, $user, $this->views($vehicle, $user));
    }

    /**
     * The id of the vehicle's latest tyre change (by id: the last one
     * recorded), the tyre reminder's occurrence; null with none.
     */
    public function latestChangeId(Vehicle $vehicle): ?int
    {
        $ids = array_map(static fn (TyreChange $c): int => $c->id, $this->tyres->listChanges($vehicle->id));

        return $ids === [] ? null : max($ids);
    }

    /**
     * @param list<TyreView> $views
     */
    private function judge(Vehicle $vehicle, User $user, array $views): TyreVerdict
    {
        // The owner's lead times, as the vehicle's tyre reminder uses (Phase 19).
        $lead = $this->reminderSettings->reminderPreferences($vehicle->userId);

        return TyreJudgement::judge($views, $this->today($user), $lead->scheduleDays, $lead->scheduleKm);
    }

    private function today(User $user): DateTimeImmutable
    {
        return LocalTime::today($this->clock, $user->preferences->timeZone());
    }

    /**
     * @return list<TyreChange> newest first
     */
    public function changes(Vehicle $vehicle): array
    {
        $changes = $this->tyres->listChanges($vehicle->id);
        usort($changes, static fn (TyreChange $a, TyreChange $b): int => TyreChange::compare($b, $a));

        return $changes;
    }

    /**
     * @return list<Tyre> in the order added
     */
    public function tyres(Vehicle $vehicle): array
    {
        return $this->tyres->listTyres($vehicle->id);
    }

    public function hasTyres(Vehicle $vehicle): bool
    {
        return $this->tyres->listTyres($vehicle->id) !== [];
    }

    /**
     * @throws TyreNotFound
     */
    public function tyre(Vehicle $vehicle, int $id): Tyre
    {
        return $this->tyres->findTyre($vehicle->id, $id) ?? throw new TyreNotFound(sprintf('Tyre %d not found.', $id));
    }

    /**
     * @return list<TyreSet> by name
     */
    public function sets(Vehicle $vehicle): array
    {
        return $this->tyres->listSets($vehicle->id);
    }

    /**
     * @throws TyreNotFound
     */
    public function set(Vehicle $vehicle, int $id): TyreSet
    {
        return $this->tyres->findSet($vehicle->id, $id) ?? throw new TyreNotFound(sprintf('Tyre set %d not found.', $id));
    }

    /**
     * Where each stored tyre was last fitted, which a swap puts it back to.
     *
     * @return array<int, ?TyrePosition> stored tyre id → position
     */
    public function lastPositions(Vehicle $vehicle): array
    {
        $result = TyreReplay::run($this->tyres->listChanges($vehicle->id));
        $positions = [];
        if ($result instanceof TyreReplayResult) {
            foreach ($result->states as $tyre => $state) {
                if ($state->status === TyreStatus::Stored) {
                    $positions[$tyre] = $state->lastPosition;
                }
            }
        }

        return $positions;
    }

    /**
     * The `tyres` service records a change dated $on may link: within
     * LINK_WINDOW_DAYS of it, nearest first, plus the one it links already.
     *
     * @return list<MaintenanceEntry>
     */
    public function linkCandidates(Vehicle $vehicle, DateTimeImmutable $on, ?int $current = null): array
    {
        $candidates = [];
        foreach ($this->entries->listForVehicle($vehicle->id) as $entry) {
            $near = abs(LocalTime::daysBetween($on, $entry->data->performedOn)) <= self::LINK_WINDOW_DAYS;
            if (($entry->data->category === MaintenanceCategory::Tyres && $near) || $entry->id === $current) {
                $candidates[] = $entry;
            }
        }
        $distance = static fn (MaintenanceEntry $e): int => abs(LocalTime::daysBetween($on, $e->data->performedOn));
        usort($candidates, static fn (MaintenanceEntry $a, MaintenanceEntry $b): int => $distance($a) <=> $distance($b));

        return $candidates;
    }

    public function updateTyre(Vehicle $vehicle, Tyre $tyre, TyreData $data): Tyre
    {
        $this->tyres->updateTyre($vehicle->id, $tyre->id, $data, $this->clock->now());

        return $this->tyre($vehicle, $tyre->id);
    }

    public function updateSet(Vehicle $vehicle, TyreSet $set, TyreSetData $data): TyreSet
    {
        $this->tyres->updateSet($vehicle->id, $set->id, $data, $this->clock->now());

        return $this->set($vehicle, $set->id);
    }

    /**
     * Whether a set has tyres that are fitted or stored (retired ones do not count).
     */
    public function isInUse(Vehicle $vehicle, TyreSet $set): bool
    {
        foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
            if ($tyre->setId === $set->id && !$tyre->isRetired()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a set that is empty; its retired tyres leave it.
     *
     * @throws TyreChangeRefused while it still holds tyres
     */
    public function deleteSet(Vehicle $vehicle, TyreSet $set): void
    {
        if ($this->isInUse($vehicle, $set)) {
            throw new TyreChangeRefused('tyre.error.set_in_use', ['set' => $set->data->name]);
        }
        foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
            if ($tyre->setId === $set->id) {
                $this->tyres->setTyreSet($vehicle->id, $tyre->id, null);
            }
        }
        $this->tyres->deleteSet($vehicle->id, $set->id);
    }

    /**
     * @param list<Tyre> $tyres
     * @param list<TyreChange> $changes
     * @return list<TyreView>
     */
    private function build(Vehicle $vehicle, User $user, array $tyres, array $changes): array
    {
        $today = $this->today($user);
        $thresholds = $this->settings->thresholds($user->id);
        $type = $vehicle->data->type;
        $result = TyreReplay::run($changes);
        $history = $this->odometer->history($vehicle);
        $current = $history->latest()?->readingKm;
        $perDay = $history->averageKmPerDay();
        $records = $this->records($vehicle);
        $byId = [];
        foreach ($changes as $change) {
            $byId[$change->id] = $change;
        }
        $retiredOn = [];
        $placedBy = [];
        $ordered = $changes;
        usort($ordered, TyreChange::compare(...));
        foreach ($ordered as $change) {
            foreach ($change->linesOf(TyreLineAction::Retire) as $line) {
                $retiredOn[$line->tyreId] = $change->data->doneOn;
            }
            foreach ([...$change->linesOf(TyreLineAction::On), ...$change->linesOf(TyreLineAction::Move)] as $line) {
                $placedBy[$line->tyreId] = $change;
            }
        }

        $views = [];
        foreach ($tyres as $tyre) {
            $segments = $result instanceof TyreReplayResult ? $result->segments($tyre->id) : [];
            $distance = TyreDistance::of($segments, $current);
            $fitting = $result instanceof TyreReplayResult ? ($byId[$result->fittedBy[$tyre->id] ?? 0] ?? null) : null;
            $made = $tyre->data->dot?->manufacturedOn;
            $wear = TyreWear::estimate(
                $result instanceof TyreReplayResult ? $result->measurements($tyre->id) : [],
                $distance->km,
                $tyre->isFitted() && $tyre->position?->isRolling() === true,
                $thresholds->replaceAt($type, $tyre->data->season),
                $thresholds->legalMinimum($type),
                $current,
                $perDay,
                $today,
            );

            $views[] = new TyreView(
                tyre: $tyre,
                distance: $distance,
                age: $made === null || $made > $today ? null : VehicleAge::between($made, $today),
                since: $fitting?->kind === TyreChangeKind::Existing ? $fitting->data->doneOn : null,
                retiredOn: $tyre->isRetired() ? ($retiredOn[$tyre->id] ?? null) : null,
                costPerKm: $this->costPerKm($tyre, $fitting, $records, $distance),
                wear: $wear,
                ageLimitOn: $tyre->isRetired() ? null : $thresholds->ageLimitOn($made),
                fittedOn: self::fittedOn($tyre, $placedBy[$tyre->id] ?? null),
            );
        }

        return $views;
    }

    /**
     * When a fitted tyre was put where it is now: its latest `on` or `move`
     * (spec.md §7.17, Phase 33.3); null when not fitted or when that was the
     * tyres already on the vehicle, counted "since" instead.
     */
    private static function fittedOn(Tyre $tyre, ?TyreChange $placedBy): ?DateTimeImmutable
    {
        return $tyre->isFitted() && $placedBy !== null && $placedBy->kind !== TyreChangeKind::Existing
            ? $placedBy->data->doneOn
            : null;
    }

    /**
     * Each tyre's share of the service record linked to the `fit` change that
     * first put it on: the record's cost split evenly across the tyres that
     * change fitted, as a retired tyre's cost per distance is (Coming up's
     * price last time, spec.md §7.18). Tyres without such a record are left
     * out; none while maintenance is off, as in the ledger.
     *
     * @param list<int> $tyreIds
     * @return array<int, string> tyre id → canonical decimal in the vehicle's currency
     */
    public function fittingShares(Vehicle $vehicle, array $tyreIds): array
    {
        $records = $this->records($vehicle);
        $changes = $this->tyres->listChanges($vehicle->id);
        $result = TyreReplay::run($changes);
        if ($records === [] || !$result instanceof TyreReplayResult) {
            return [];
        }
        $byId = [];
        foreach ($changes as $change) {
            $byId[$change->id] = $change;
        }

        $shares = [];
        foreach ($tyreIds as $tyreId) {
            $fitting = $byId[$result->fittedBy[$tyreId] ?? 0] ?? null;
            $record = $fitting?->kind === TyreChangeKind::Fit && $fitting->data->maintenanceEntryId !== null
                ? ($records[$fitting->data->maintenanceEntryId] ?? null)
                : null;
            $fitted = $fitting === null ? 0 : count($fitting->linesOf(TyreLineAction::On));
            if ($record !== null && $fitted > 0) {
                $shares[$tyreId] = Decimal::divide($record->data->cost, (string) $fitted, 6);
            }
        }

        return $shares;
    }

    /**
     * @param array<int, MaintenanceEntry> $records
     */
    private function costPerKm(Tyre $tyre, ?TyreChange $fitting, array $records, TyreDistanceFigure $distance): ?string
    {
        if (!$tyre->isRetired() || $fitting?->kind !== TyreChangeKind::Fit || $fitting->data->maintenanceEntryId === null) {
            return null;
        }
        $record = $records[$fitting->data->maintenanceEntryId] ?? null;

        return $record === null
            ? null
            : TyreDistance::costPerKm($record->data->cost, count($fitting->linesOf(TyreLineAction::On)), $distance->km);
    }

    /**
     * The vehicle's service records by id, or none while maintenance is off.
     *
     * @return array<int, MaintenanceEntry>
     */
    private function records(Vehicle $vehicle): array
    {
        if (!$this->features->isEnabled(Feature::Maintenance)) {
            return [];
        }
        $records = [];
        foreach ($this->entries->listForVehicle($vehicle->id) as $entry) {
            $records[$entry->id] = $entry;
        }

        return $records;
    }

    /**
     * @param list<TyreSet> $sets
     * @return array<int, TyreSet>
     */
    public static function setsById(array $sets): array
    {
        $byId = [];
        foreach ($sets as $set) {
            $byId[$set->id] = $set;
        }

        return $byId;
    }
}
