<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;

/**
 * The recent activity widget (spec.md §7.8): the latest entries across
 * fill-ups, manual readings, services, documents and expenses. Fill-ups and
 * readings are instants and the rest calendar dates, so everything is placed
 * on the owner's calendar first. Readings written by a fill-up or a service
 * are left out (the entry itself is listed), and so are switched-off modules.
 */
final readonly class RecentActivity
{
    public const int LIMIT = 8;

    public function __construct(
        private FuelEntryRepository $fuel,
        private OdometerReadingRepository $readings,
        private MaintenanceEntryRepository $maintenance,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenses,
        private VehicleService $vehicles,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<ActivityItem> newest first
     */
    public function latest(User $user, array $vehicles, int $limit = self::LIMIT): array
    {
        $enabled = $this->features->all();
        $items = [];
        foreach ($vehicles as $vehicle) {
            array_push($items, ...$this->forVehicle($user, $vehicle, $enabled));
        }
        usort($items, ActivityItem::compare(...));

        return array_slice($items, 0, $limit);
    }

    /**
     * @param array<string, bool> $enabled module → switched on
     * @return list<ActivityItem>
     */
    private function forVehicle(User $user, Vehicle $vehicle, array $enabled): array
    {
        $zone = $user->preferences->timeZone();
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $items = [];

        if ($enabled[Feature::Fuel->value]) {
            foreach ($this->fuel->listForVehicle($vehicle->id) as $entry) {
                $items[] = new ActivityItem(
                    kind: ActivityKind::Fuel,
                    vehicle: $vehicle,
                    entryId: $entry->id,
                    date: LocalTime::dateOf($entry->data->filledAt, $zone),
                    createdAt: $entry->createdAt,
                    label: '',
                    labelKey: 'dashboard.activity.fill_up',
                    icon: $entry->data->fuel->isElectric() ? 'ev_station' : 'local_gas_station',
                    amount: $entry->data->totalCost,
                    currency: $currency,
                );
            }
        }

        foreach ($this->readings->listForVehicle($vehicle->id) as $reading) {
            if ($reading->source !== OdometerSource::Manual) {
                continue;
            }
            $items[] = new ActivityItem(
                kind: ActivityKind::Odometer,
                vehicle: $vehicle,
                entryId: $reading->id,
                date: LocalTime::dateOf($reading->recordedAt, $zone),
                createdAt: $reading->createdAt,
                label: '',
                labelKey: 'dashboard.activity.reading',
                icon: 'speed',
                readingKm: $reading->readingKm,
            );
        }

        if ($enabled[Feature::Maintenance->value]) {
            foreach ($this->maintenance->listForVehicle($vehicle->id) as $entry) {
                $items[] = new ActivityItem(
                    kind: ActivityKind::Maintenance,
                    vehicle: $vehicle,
                    entryId: $entry->id,
                    date: $entry->data->performedOn,
                    createdAt: $entry->createdAt,
                    label: $entry->data->title,
                    labelKey: 'maintenance.category.' . $entry->data->category->value,
                    icon: $entry->data->category->icon(),
                    amount: $entry->data->cost,
                    currency: $currency,
                );
            }
        }

        if ($enabled[Feature::Compliance->value]) {
            foreach ($this->documents->listForVehicle($vehicle->id) as $document) {
                // Dated as in the cost ledger: its start, else the day it was added.
                $items[] = new ActivityItem(
                    kind: ActivityKind::Document,
                    vehicle: $vehicle,
                    entryId: $document->id,
                    date: $document->data->startOn ?? LocalTime::dateOf($document->createdAt, $zone),
                    createdAt: $document->createdAt,
                    label: $document->data->title ?? '',
                    labelKey: 'compliance.type.' . $document->data->type->value,
                    icon: $document->data->type->icon(),
                    amount: $document->data->cost,
                    currency: $currency,
                );
            }
        }

        foreach ($this->expenses->listForVehicle($vehicle->id) as $expense) {
            $items[] = new ActivityItem(
                kind: ActivityKind::Expense,
                vehicle: $vehicle,
                entryId: $expense->id,
                date: $expense->data->spentOn,
                createdAt: $expense->createdAt,
                label: '',
                labelKey: 'expense.category.' . $expense->data->category->value,
                icon: $expense->data->category->icon(),
                amount: $expense->data->amount,
                currency: $currency,
            );
        }

        return $items;
    }
}
