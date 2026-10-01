<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Money\Money;

/**
 * The records an incident links, and the ones that could be linked
 * (spec.md §7.29 *Incident page*), as display rows. A switched-off
 * module's records are left out, as everywhere else.
 */
final readonly class IncidentRecords
{
    /** The picker offers records dated up to this many days after the incident. */
    public const int PICKER_DAYS = 180;

    public function __construct(
        private MaintenanceEntryRepository $maintenance,
        private ExpenseEntryRepository $expenses,
        private TyreRepository $tyres,
        private VehicleService $vehicles,
        private FeatureToggles $features,
    ) {
    }

    /**
     * Every record of the vehicle, linked or not, newest first.
     *
     * @return list<LinkedRecord>
     */
    public function all(User $user, Vehicle $vehicle): array
    {
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $enabled = $this->features->all();
        $records = [];
        if ($enabled[Feature::Maintenance->value]) {
            foreach ($this->maintenance->listForVehicle($vehicle->id) as $entry) {
                $records[] = new LinkedRecord(
                    LinkKind::Maintenance,
                    $entry->id,
                    $entry->data->performedOn,
                    $entry->data->title,
                    false,
                    $entry->data->vendor,
                    Money::of($entry->data->cost, $currency),
                    'maintenance.edit',
                    ['id' => (string) $vehicle->id, 'entry' => (string) $entry->id],
                    $entry->createdBy,
                    incidentId: $entry->incidentId,
                );
            }
        }
        foreach ($this->expenses->listForVehicle($vehicle->id) as $entry) {
            $records[] = new LinkedRecord(
                LinkKind::Expense,
                $entry->id,
                $entry->data->spentOn,
                $entry->data->note ?? 'expense.category.' . $entry->data->category->value,
                $entry->data->note === null,
                null,
                Money::of($entry->data->amount, $currency),
                'expenses.edit',
                ['id' => (string) $vehicle->id, 'entry' => (string) $entry->id],
                $entry->createdBy,
                incidentId: $entry->incidentId,
            );
        }
        if ($enabled[Feature::Tyres->value]) {
            foreach ($this->tyres->listChanges($vehicle->id) as $change) {
                $records[] = new LinkedRecord(
                    LinkKind::Tyre,
                    $change->id,
                    $change->data->doneOn,
                    'tyre.kind.' . $change->kind->value,
                    true,
                    null,
                    null,
                    'tyres.changes.edit',
                    ['id' => (string) $vehicle->id, 'change' => (string) $change->id],
                    $change->createdBy,
                    $change->data->maintenanceEntryId !== null,
                    $change->incidentId,
                );
            }
        }
        usort($records, static fn (LinkedRecord $a, LinkedRecord $b): int => ($b->date <=> $a->date) ?: $b->id <=> $a->id);

        return $records;
    }

    /**
     * @param list<LinkedRecord> $records from all()
     * @return list<LinkedRecord> the incident's, newest first
     */
    public static function linkedTo(Incident $incident, array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn (LinkedRecord $record): bool => $record->incidentId === $incident->id,
        ));
    }

    /**
     * What *Link a record* offers: records linked to no incident, dated from
     * the incident's date to PICKER_DAYS after it; never a tyre change that
     * follows its service record.
     *
     * @param list<LinkedRecord> $records from all()
     * @return list<LinkedRecord>
     */
    public static function candidates(Incident $incident, array $records): array
    {
        $from = $incident->data->occurredOn;
        $until = $from->modify(sprintf('+%d days', self::PICKER_DAYS));

        return array_values(array_filter(
            $records,
            static fn (LinkedRecord $record): bool => $record->incidentId === null
                && !$record->followsRecord
                && $record->date >= $from
                && $record->date <= $until,
        ));
    }

    /**
     * @param list<LinkedRecord> $records from all()
     */
    public static function find(array $records, LinkKind $kind, int $id): ?LinkedRecord
    {
        foreach ($records as $record) {
            if ($record->kind === $kind && $record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    public static function inWindow(Incident $incident, DateTimeImmutable $date): bool
    {
        return $date >= $incident->data->occurredOn
            && $date <= $incident->data->occurredOn->modify(sprintf('+%d days', self::PICKER_DAYS));
    }
}
