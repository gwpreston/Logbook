<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\ValuationRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Number\Decimal;

/**
 * Business mileage per vehicle for a period (spec.md §7.7, §7.22), for the
 * Reports section and the claim report.
 *
 * Cost per business distance is the vehicle's cost of ownership per
 * distance for the period (§7.7): the period's running costs per km plus
 * the vehicle's depreciation per km, each over its own period, or the
 * running part alone when there is no value. It is left out when there is
 * no distance or no running cost, and never shown without `ViewCosts`.
 */
final readonly class BusinessMileage
{
    public function __construct(
        private MileageSplit $split,
        private ClaimReportService $claims,
        private ReportService $reports,
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private ValuationRepository $valuations,
        private OdometerReadingRepository $readings,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @param DateTimeImmutable $from first day, inclusive
     * @param DateTimeImmutable $to last day, inclusive
     * @return BusinessMileageReport the vehicles with business trips or a claim in the period
     */
    public function build(
        User $user,
        array $vehicles,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $today,
    ): BusinessMileageReport {
        if ($vehicles === []) {
            return new BusinessMileageReport([]);
        }
        $ids = array_map(static fn (Vehicle $vehicle): int => $vehicle->id, $vehicles);
        $claim = $this->claims->build($user, new ClaimFilter($from, $to, null, $ids));
        $costs = $this->costPerKm($user, $vehicles, $from, $to, $today);

        $rows = [];
        $claimed = [];
        $businessKm = '0';
        $totalKm = null;
        foreach ($vehicles as $vehicle) {
            $split = $this->split->forVehicle($user, $vehicle, $from, $to);
            $own = array_values(array_filter(
                $claim->rows,
                static fn (ValuedTrip $row): bool => $row->trip->vehicleId === $vehicle->id,
            ));
            if (Decimal::compare($split->businessKm, '0') <= 0 && $own === []) {
                continue;
            }
            $claimedKm = '0';
            foreach ($own as $row) {
                if ($row->isValued()) {
                    $claimedKm = Decimal::add($claimedKm, $row->trip->data->distanceKm);
                }
            }
            array_push($claimed, ...$own);
            if (!$split->totalOnly) {
                $businessKm = Decimal::add($businessKm, $split->businessKm);
                if ($split->totalKm !== null) {
                    $totalKm = Decimal::add($totalKm ?? '0', $split->totalKm);
                }
            }
            $cost = $costs[$vehicle->id] ?? null;
            $rows[] = new BusinessMileageRow(
                vehicle: $vehicle,
                split: $split,
                claim: ClaimTotals::byCurrency($own),
                claimedKm: $claimedKm,
                costPerKm: $cost['perKm'] ?? null,
                currency: $this->vehicles->currencyFor($user, $vehicle),
                costIsRunningOnly: $cost['runningOnly'] ?? false,
            );
        }

        return new BusinessMileageReport($rows, $businessKm, $totalKm, ClaimTotals::byCurrency($claimed));
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return array<int, array{perKm: string, runningOnly: bool}>
     */
    private function costPerKm(
        User $user,
        array $vehicles,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $today,
    ): array {
        $visible = array_values(array_filter(
            $vehicles,
            fn (Vehicle $vehicle): bool => $this->access->can($user, VehicleAbility::ViewCosts, $vehicle),
        ));
        if ($visible === []) {
            return [];
        }
        $report = $this->reports->forVehicles(
            $user,
            new ReportFilter(new ReportPeriod(ReportRange::Custom, $from, $to)),
            $visible,
        );

        $running = [];
        foreach ($report->currencies as $currency) {
            foreach ($currency->vehicles as $cost) {
                if ($cost->costPerKm !== null && !$cost->total->isZero()) {
                    $running[$cost->vehicle->id] = $cost->costPerKm;
                }
            }
        }

        $zone = $user->preferences->timeZone();
        $costs = [];
        foreach ($visible as $vehicle) {
            $perKm = $running[$vehicle->id] ?? null;
            if ($perKm === null) {
                continue;
            }
            $depreciation = Depreciation::of(
                $vehicle,
                $this->valuations->listForVehicle($vehicle->id),
                $this->readings->listForVehicle($vehicle->id),
                $today,
                $zone,
                $this->vehicles->currencyFor($user, $vehicle),
            );
            $costs[$vehicle->id] = $depreciation->perKm === null
                ? ['perKm' => $perKm, 'runningOnly' => true]
                : ['perKm' => Decimal::add($perKm, $depreciation->perKm), 'runningOnly' => false];
        }

        return $costs;
    }
}
