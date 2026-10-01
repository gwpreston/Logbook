<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Service\Incident\IncidentAccess;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Money\Money;
use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;

/**
 * Cost of ownership (spec.md §7.7): gathers a vehicle's ledger lines,
 * mileage log and depreciation and hands them to OwnershipCost; for the
 * ownership report, groups the vehicles by currency as reports do.
 */
final readonly class OwnershipService
{
    public function __construct(
        private VehicleService $vehicles,
        private CostLedger $ledger,
        private OdometerReadingRepository $readings,
        private ValuationService $valuations,
        private IncidentRepository $incidents,
        private FeatureToggles $features,
        private IncidentAccess $incidentAccess,
    ) {
    }

    /**
     * The vehicle's insurance payouts, when incidents are on (spec.md §7.29).
     * A payout is a claim detail: only those the viewer may see count, so
     * anyone else gets the running costs as spent.
     *
     * @return list<InsurancePayout>
     */
    public function payouts(User $user, Vehicle $vehicle): array
    {
        if (!$this->features->isEnabled(Feature::Incidents)) {
            return [];
        }
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $payouts = [];
        foreach ($this->incidents->listForVehicle($vehicle->id) as $incident) {
            $payout = $incident->data->claim->payout;
            if ($payout !== null && $this->incidentAccess->seesDetails($user, $vehicle, $incident)) {
                $payouts[] = new InsurancePayout($incident->data->occurredOn, Money::of($payout, $currency), $incident->id);
            }
        }

        return $payouts;
    }

    /**
     * For the overview card, which has already worked out the vehicle's
     * depreciation and read its mileage log.
     *
     * @param list<OdometerReading> $readings oldest first
     */
    public function forVehicle(
        User $user,
        Vehicle $vehicle,
        array $readings,
        Depreciation $depreciation,
        DateTimeImmutable $today,
    ): ?OwnershipCost {
        return OwnershipCost::of(
            $vehicle,
            $this->ledger->items($user, [$vehicle]),
            $readings,
            $depreciation,
            $today,
            $user->preferences->timeZone(),
            $this->payouts($user, $vehicle),
        );
    }

    public function report(User $user, ReportFilter $filter, DateTimeImmutable $today): OwnershipReport
    {
        // Only vehicles whose costs the user may see count (spec.md §5 Costs).
        $vehicles = $filter->scope($this->vehicles->listWith($user, VehicleAbility::ViewCosts, true));
        $zone = $user->preferences->timeZone();

        /** @var array<int, list<CostItem>> $items */
        $items = [];
        foreach ($this->ledger->items($user, $vehicles) as $item) {
            $items[$item->vehicle->id][] = $item;
        }

        /** @var array<string, list<OwnershipCost>> $byCurrency */
        $byCurrency = [];
        foreach ($vehicles as $vehicle) {
            $currency = $this->vehicles->currencyFor($user, $vehicle);
            $readings = $this->readings->listForVehicle($vehicle->id);
            $depreciation = Depreciation::of(
                $vehicle,
                $this->valuations->forVehicle($vehicle),
                $readings,
                $today,
                $zone,
                $currency,
            );
            $cost = OwnershipCost::of(
                $vehicle,
                $items[$vehicle->id] ?? [],
                $readings,
                $depreciation,
                $today,
                $zone,
                $this->payouts($user, $vehicle),
            );
            if ($cost !== null) {
                $byCurrency[$currency][] = $cost;
            }
        }
        uksort($byCurrency, static fn (string $a, string $b): int
            => (count($byCurrency[$b]) <=> count($byCurrency[$a])) ?: strcmp($a, $b));

        $sections = [];
        foreach ($byCurrency as $currency => $rows) {
            $sections[] = OwnershipSection::of($currency, $rows);
        }

        return new OwnershipReport($filter, $vehicles, $sections);
    }
}
