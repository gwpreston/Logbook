<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Money\Currency;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * True cost (spec.md §7.35): gathers each vehicle's ledger lines, mileage
 * log, value points and payouts once and works out every period from them.
 * Only vehicles whose costs the user may see are ever worked out.
 */
final readonly class TrueCostService
{
    /** Below this, in the currency's smallest unit per distance unit, a change joins *Other small changes*. */
    private const string SMALL = '0.2';

    public function __construct(
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private CostLedger $ledger,
        private OdometerReadingRepository $readings,
        private ValuationService $valuations,
        private OwnershipService $ownership,
    ) {
    }

    /**
     * Null when the user may not see its costs or it has no ownership
     * period (no purchase date and nothing logged).
     */
    public function forVehicle(User $user, Vehicle $vehicle, DateTimeImmutable $today): ?VehicleTrueCost
    {
        return $this->forVehicles($user, [$vehicle], $today)[0] ?? null;
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<VehicleTrueCost> in the order given, leaving out those without one
     */
    public function forVehicles(User $user, array $vehicles, DateTimeImmutable $today): array
    {
        $vehicles = array_values(array_filter(
            $vehicles,
            fn (Vehicle $vehicle): bool => $this->access->can($user, VehicleAbility::ViewCosts, $vehicle),
        ));
        if ($vehicles === []) {
            return [];
        }
        /** @var array<int, list<CostItem>> $items */
        $items = [];
        foreach ($this->ledger->items($user, $vehicles) as $item) {
            $items[$item->vehicle->id][] = $item;
        }

        $results = [];
        foreach ($vehicles as $vehicle) {
            $result = $this->build($user, $vehicle, $items[$vehicle->id] ?? [], $today);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * The vehicles in view whose costs the user may see, with their true
     * cost.
     *
     * @return list<VehicleTrueCost>
     */
    public function fleet(User $user, ReportFilter $filter, DateTimeImmutable $today): array
    {
        return $this->forVehicles(
            $user,
            $filter->scope($this->vehicles->listWith($user, VehicleAbility::ViewCosts, true)),
            $today,
        );
    }

    /**
     * The threshold for *Other small changes* per km: 0.2 of the currency's
     * smallest unit per the user's distance unit (0.2p a mile).
     */
    public static function smallPerKm(string $currency, DistanceUnit $unit): string
    {
        $perUnit = Decimal::divide(self::SMALL, (string) (10 ** Currency::fractionDigits($currency)), 6);

        return $unit === DistanceUnit::Mile
            ? Decimal::divide($perUnit, DistanceUnit::KM_PER_MILE_DECIMAL, 9)
            : $perUnit;
    }

    /**
     * @param list<CostItem> $items the vehicle's
     */
    private function build(User $user, Vehicle $vehicle, array $items, DateTimeImmutable $today): ?VehicleTrueCost
    {
        $zone = $user->preferences->timeZone();
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $readings = $this->readings->listForVehicle($vehicle->id);
        $valuations = $this->valuations->forVehicle($vehicle);
        $payouts = $this->ownership->payouts($user, $vehicle);
        $depreciation = Depreciation::of($vehicle, $valuations, $readings, $today, $zone, $currency);
        $ownership = OwnershipCost::of($vehicle, $items, $readings, $depreciation, $today, $zone, $payouts);
        if ($ownership === null || $ownership->period->from === null) {
            return null;
        }
        $curve = ValueCurve::of($vehicle, $valuations);
        $from = $ownership->period->from;
        $to = $ownership->period->to;
        $period = fn (?TrueCostPeriod $p, int $minDays = 0): ?TrueCost => $p === null
            ? null
            : TrueCost::forPeriod($vehicle, $currency, $p, $items, $readings, $curve, $payouts, $zone, $minDays);

        $years = [];
        foreach (TrueCostPeriod::years($from, $to) as $year) {
            $years[] = $period($year);
        }
        $years = array_values(array_filter($years));
        $small = self::smallPerKm($currency, $user->preferences->distanceUnit);
        $changes = [];
        for ($i = 1, $n = count($years); $i < $n; $i++) {
            $change = CostChange::between($years[$i - 1], $years[$i], $small);
            if ($change !== null && $years[$i]->period->year !== null) {
                $changes[$years[$i]->period->year] = $change;
            }
        }

        return new VehicleTrueCost(
            vehicle: $vehicle,
            currency: $currency,
            ownership: $ownership,
            sinceBought: TrueCost::sinceBought($ownership, $items),
            lastTwelveMonths: $period(TrueCostPeriod::twelveMonths($today, $from, $to), OwnershipCost::MIN_DAYS),
            previousTwelveMonths: $period(TrueCostPeriod::twelveMonths($today, $from, $to, true), OwnershipCost::MIN_DAYS),
            years: $years,
            changes: $changes,
        );
    }
}
