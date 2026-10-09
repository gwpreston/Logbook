<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\MaintenanceScheduleRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Repository\ValuationRepository;

/**
 * Reads what the widgets and services of a page will each ask for per
 * vehicle, once for all the vehicles on it (spec.md §8 *Page budgets*):
 * one query per table instead of one per vehicle per widget. Their
 * per-vehicle reads then find it already read. Does nothing outside a page
 * request (RequestReads is off there).
 */
final readonly class VehicleDataPrimer
{
    public function __construct(
        private FuelEntryRepository $fuel,
        private OdometerReadingRepository $readings,
        private MaintenanceEntryRepository $maintenance,
        private MaintenanceScheduleRepository $schedules,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenses,
        private ValuationRepository $valuations,
        private FinanceAgreementRepository $finance,
        private IncidentRepository $incidents,
        private TyreRepository $tyres,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     */
    public function prime(array $vehicles): void
    {
        $ids = array_map(static fn (Vehicle $vehicle): int => $vehicle->id, $vehicles);
        if ($ids === []) {
            return;
        }

        $this->fuel->prime($ids);
        $this->readings->prime($ids);
        $this->maintenance->prime($ids);
        $this->schedules->prime($ids);
        $this->documents->prime($ids);
        $this->expenses->prime($ids);
        $this->valuations->prime($ids);
        $this->finance->prime($ids);
        $this->incidents->prime($ids);
        $this->tyres->prime($ids);
    }
}
