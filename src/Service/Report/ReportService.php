<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Vehicle\VehicleService;

/**
 * Builds reports (spec.md §7.7): gathers the owner's vehicles, their ledger
 * lines and mileage logs, and hands them to ReportCalculator.
 */
final readonly class ReportService
{
    public function __construct(
        private VehicleService $vehicles,
        private CostLedger $ledger,
        private OdometerReadingRepository $readings,
    ) {
    }

    public function build(User $user, ReportFilter $filter): Report
    {
        // Only vehicles whose costs the user may see count (spec.md §5 Costs).
        $vehicles = $this->vehicles->listWith($user, VehicleAbility::ViewCosts, true);

        return $this->forVehicles($user, $filter, $filter->scope($vehicles));
    }

    /**
     * Several reports over the same vehicles (e.g. this month and last
     * month), reading the ledger once.
     *
     * @param list<Vehicle> $vehicles
     * @param list<ReportFilter> $filters
     * @return list<Report> in the order of $filters
     */
    public function compare(User $user, array $vehicles, array $filters): array
    {
        [$currencies, $items, $readings] = $this->gather($user, $vehicles);

        return array_map(
            static fn (ReportFilter $filter): Report => ReportCalculator::build(
                $filter,
                $vehicles,
                $currencies,
                $items,
                $readings,
                $user->preferences->timeZone(),
            ),
            $filters,
        );
    }

    /**
     * @param list<Vehicle> $vehicles
     */
    public function forVehicles(User $user, ReportFilter $filter, array $vehicles): Report
    {
        return $this->compare($user, $vehicles, [$filter])[0];
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return array{0: array<int, string>, 1: list<CostItem>, 2: array<int, list<OdometerReading>>}
     */
    private function gather(User $user, array $vehicles): array
    {
        $currencies = [];
        $readings = [];
        foreach ($vehicles as $vehicle) {
            $currencies[$vehicle->id] = $this->vehicles->currencyFor($user, $vehicle);
            $readings[$vehicle->id] = $this->readings->listForVehicle($vehicle->id);
        }

        return [$currencies, $this->ledger->items($user, $vehicles), $readings];
    }
}
