<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeZone;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostItem;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * Aggregates ledger lines into a Report (spec.md §7.7). Pure: no database, no
 * clock, so every total can be unit-tested.
 *
 * Money is summed as Money (integer micro-units) per currency and never
 * converted; distances are kilometres and quotients are exact decimals.
 * Conversion to the owner's units happens only when displayed.
 */
final class ReportCalculator
{
    /** Places kept for money per km and the monthly average. */
    private const int SCALE = 6;

    /**
     * @param list<Vehicle> $vehicles the vehicles covered (ReportFilter::scope())
     * @param array<int, string> $currencies vehicle id → its currency
     * @param list<CostItem> $items ledger lines of those vehicles, any date, oldest first
     * @param array<int, list<OdometerReading>> $readings vehicle id → mileage log, oldest first
     */
    public static function build(
        ReportFilter $filter,
        array $vehicles,
        array $currencies,
        array $items,
        array $readings,
        DateTimeZone $zone,
    ): Report {
        $covered = [];
        foreach ($vehicles as $vehicle) {
            $covered[$vehicle->id] = true;
        }
        $items = array_values(array_filter(
            $items,
            static fn (CostItem $item): bool => isset($covered[$item->vehicle->id]),
        ));

        $period = $filter->period->resolve($items === [] ? null : $items[0]->date);
        $inPeriod = array_values(array_filter($items, static fn (CostItem $item): bool => $period->contains($item->date)));

        /** @var array<string, list<Vehicle>> $byCurrency */
        $byCurrency = [];
        foreach ($vehicles as $vehicle) {
            $byCurrency[$currencies[$vehicle->id]][] = $vehicle;
        }
        uksort($byCurrency, static fn (string $a, string $b): int
            => (count($byCurrency[$b]) <=> count($byCurrency[$a])) ?: strcmp($a, $b));

        $sections = [];
        foreach ($byCurrency as $currency => $group) {
            $sections[] = self::currencyReport($currency, $group, $inPeriod, $readings, $period, $zone);
        }

        // Only currencies with something spent, unless nothing was spent at all.
        $spent = array_values(array_filter($sections, static fn (CurrencyReport $s): bool => !$s->isEmpty()));
        $sections = $spent !== [] ? $spent : array_slice($sections, 0, 1);

        return new Report($filter, $period, $vehicles, $sections, $inPeriod);
    }

    /**
     * @param list<Vehicle> $vehicles the vehicles using $currency
     * @param list<CostItem> $items the period's lines (any currency)
     * @param array<int, list<OdometerReading>> $readings
     */
    private static function currencyReport(
        string $currency,
        array $vehicles,
        array $items,
        array $readings,
        ReportPeriod $period,
        DateTimeZone $zone,
    ): CurrencyReport {
        $zero = Money::zero($currency);
        $items = array_values(array_filter($items, static fn (CostItem $item): bool => $item->currency() === $currency));

        /** @var array<string, Money> $emptyGroups */
        $emptyGroups = array_fill_keys(array_map(static fn (CostGroup $g): string => $g->value, CostGroup::cases()), $zero);
        $total = $zero;
        $groups = $emptyGroups;
        /** @var array<string, Money> $monthTotals keyed Y-m */
        $monthTotals = [];
        /** @var array<string, array<string, Money>> $monthGroups keyed Y-m, then group */
        $monthGroups = [];
        foreach ($period->months() as $month) {
            $monthTotals[$month->format('Y-m')] = $zero;
            $monthGroups[$month->format('Y-m')] = $emptyGroups;
        }
        /** @var array<int, Money> $vehicleTotals */
        $vehicleTotals = [];
        /** @var array<int, int> $vehicleCounts */
        $vehicleCounts = [];

        foreach ($items as $item) {
            $group = $item->group()->value;
            $key = $item->date->format('Y-m');
            $id = $item->vehicle->id;
            $total = $total->add($item->amount);
            $groups[$group] = $groups[$group]->add($item->amount);
            $monthTotals[$key] = $monthTotals[$key]->add($item->amount);
            $monthGroups[$key][$group] = $monthGroups[$key][$group]->add($item->amount);
            $vehicleTotals[$id] = ($vehicleTotals[$id] ?? $zero)->add($item->amount);
            $vehicleCounts[$id] = ($vehicleCounts[$id] ?? 0) + 1;
        }

        // Distance counts only for vehicles with costs in the period: miles
        // driven by a vehicle whose costs were never logged would make the
        // fleet's running cost look cheaper than any single vehicle's.
        $distance = '0';
        $vehicleCosts = [];
        foreach ($vehicles as $vehicle) {
            $count = $vehicleCounts[$vehicle->id] ?? 0;
            if ($count === 0) {
                continue;
            }
            $km = PeriodDistance::km($readings[$vehicle->id] ?? [], $period, $zone);
            if ($km !== null) {
                $distance = Decimal::add($distance, $km);
            }
            $spent = $vehicleTotals[$vehicle->id] ?? $zero;
            $vehicleCosts[] = new VehicleCost(
                $vehicle,
                $spent,
                $count,
                $km,
                self::perKm($spent, $km),
                self::share($spent, $total),
            );
        }
        usort($vehicleCosts, static fn (VehicleCost $a, VehicleCost $b): int
            => ($b->total->micros <=> $a->total->micros) ?: $a->vehicle->id <=> $b->vehicle->id);

        $distanceKm = Decimal::compare($distance, '0') > 0 ? $distance : null;
        $months = [];
        foreach ($period->months() as $month) {
            $key = $month->format('Y-m');
            $months[] = new MonthTotal($month, $monthTotals[$key], $monthGroups[$key]);
        }

        return new CurrencyReport(
            currency: $currency,
            total: $total,
            count: count($items),
            groups: array_map(
                static fn (CostGroup $g): GroupTotal
                    => new GroupTotal($g, $groups[$g->value], self::share($groups[$g->value], $total)),
                CostGroup::cases(),
            ),
            months: $months,
            vehicles: $vehicleCosts,
            distanceKm: $distanceKm,
            costPerKm: self::perKm($total, $distanceKm),
            averagePerMonth: Money::of(
                Decimal::divide($total->toDecimal(Money::SCALE), (string) max(1, count($months)), self::SCALE),
                $currency,
            ),
        );
    }

    private static function perKm(Money $amount, ?string $km): ?string
    {
        if ($km === null || Decimal::compare($km, '0') <= 0) {
            return null;
        }

        return Decimal::divide($amount->toDecimal(Money::SCALE), $km, self::SCALE);
    }

    private static function share(Money $part, Money $total): float
    {
        return $total->micros === 0 ? 0.0 : $part->micros / $total->micros * 100;
    }
}
