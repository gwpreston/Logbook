<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Fuel\GradeVerdict;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Fuel\FuelStatistics;
use Logbook\Service\Fuel\FuelTotals;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\GroupTotal;
use Logbook\Service\Report\MonthTotal;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\VehicleCost;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\QueryParams;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\PercentDifference;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The four reports over the API (spec.md §7.7, §7.20 *Phase 39*), from the
 * services the Reports page and Ask's tools use, so all three agree. The
 * page's parameters select them: `range` (`month`, `3m`, `12m`, `ytd`,
 * `all`, `custom` with `from` / `to`), `vehicle`, `include_archived`.
 * Costs count only vehicles whose costs the key's user may see; the others
 * in scope are listed in `excluded`. Money is in each vehicle's currency
 * and never added across currencies.
 */
final readonly class ApiReports
{
    private const int SCALE = 6;

    public function __construct(
        private ReportService $reports,
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private FuelService $fuel,
        private OdometerService $odometer,
        private ApiReader $reader,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Totals by category group, month or vehicle (`group_by`), per currency,
     * with distance driven; `group` narrows to one cost group.
     *
     * @return array<string, mixed>
     */
    public function costs(User $user, ServerRequestInterface $request): array
    {
        $groupBy = $request->getQueryParams()['group_by'] ?? 'category';
        if (!in_array($groupBy, ['category', 'month', 'vehicle'], true)) {
            throw ApiProblem::invalidParameter('group_by', 'one of category, month or vehicle.');
        }
        $group = QueryParams::code($request, 'group', CostGroup::class);
        [$filter, $counted, $excluded] = $this->costScope($user, $request, $group);
        $report = $this->reports->forVehicles($user, $filter, $counted);

        return $this->head($report->period, $counted, $excluded) + [
            'group' => $filter->group?->value,
            'group_by' => $groupBy,
            'currencies' => array_map(fn (CurrencyReport $section): array => [
                ...$this->section($section),
                ...match ($groupBy) {
                    'month' => ['by_month' => array_map(static fn (MonthTotal $m): array => [
                        'month' => $m->month->format('Y-m'),
                        'total' => $m->total->toDecimal(Serializer::QUANTITY_SCALE),
                    ], $section->months)],
                    'vehicle' => ['by_vehicle' => array_map(static fn (VehicleCost $v): array => [
                        'vehicle_id' => $v->vehicle->id,
                        'total' => $v->total->toDecimal(Serializer::QUANTITY_SCALE),
                        'entries' => $v->count,
                        'distance_km' => Serializer::dec($v->distanceKm, Serializer::QUANTITY_SCALE),
                    ], $section->vehicles)],
                    default => ['by_category' => array_map(static fn (GroupTotal $g): array => [
                        'group' => $g->group->value,
                        'total' => $g->amount->toDecimal(Serializer::QUANTITY_SCALE),
                    ], $section->groups)],
                },
            ], $report->isEmpty() ? [] : $report->currencies),
        ];
    }

    /**
     * Cost per distance for each vehicle and all of them, per currency.
     *
     * @return array<string, mixed>
     */
    public function costPerDistance(User $user, ServerRequestInterface $request): array
    {
        [$filter, $counted, $excluded] = $this->costScope($user, $request, null);
        $report = $this->reports->forVehicles($user, $filter, $counted);

        return $this->head($report->period, $counted, $excluded) + [
            'currencies' => array_map(fn (CurrencyReport $section): array => [
                ...$this->section($section),
                'vehicles' => array_map(static fn (VehicleCost $v): array => [
                    'vehicle_id' => $v->vehicle->id,
                    'total' => $v->total->toDecimal(Serializer::QUANTITY_SCALE),
                    'distance_km' => Serializer::dec($v->distanceKm, Serializer::QUANTITY_SCALE),
                    'cost_per_km' => Serializer::dec($v->costPerKm, self::SCALE),
                ], $section->vehicles),
            ], $report->isEmpty() ? [] : $report->currencies),
        ];
    }

    /**
     * Phase 16's fuel statistics per vehicle and kind of energy, by grade,
     * with the grade verdicts (over the whole history, as the Fuel tab).
     * Spend and price only where the key's user may see costs.
     *
     * @return array<string, mixed>
     */
    public function fuel(User $user, ServerRequestInterface $request): array
    {
        [$period, $vehicles] = $this->scope($user, $request);
        $grade = QueryParams::code($request, 'grade', FuelGrade::class);
        $zone = $user->preferences->timeZone();

        $rows = [];
        foreach ($vehicles as $vehicle) {
            $history = $this->fuel->history($vehicle);
            $fills = FuelStatistics::inPeriod($history->fills, $period->from, $period->to, $zone, $grade);
            if ($fills === []) {
                continue;
            }
            $costs = $this->reader->costs($user, $vehicle);
            $currency = $this->vehicles->currencyFor($user, $vehicle);
            $breakdowns = $this->fuel->gradeBreakdowns($history);
            $kinds = [];
            foreach (EnergyKind::cases() as $kind) {
                $ofKind = array_values(array_filter(
                    $fills,
                    static fn (FillEconomy $f): bool => $f->entry->data->fuel->kind() === $kind,
                ));
                if ($ofKind === []) {
                    continue;
                }
                $byGrade = [];
                foreach (FuelStatistics::byGrade($ofKind) as $key => $group) {
                    $of = $key === '' ? null : FuelGrade::from($key);
                    $byGrade[] = ['grade' => $of?->value] + $this->totals(FuelStatistics::totals($group, $of), $kind, $costs);
                }
                $breakdown = $breakdowns[$kind->value] ?? null;
                $kinds[] = ['kind' => $kind->value]
                    + $this->totals(FuelStatistics::totals($ofKind), $kind, $costs)
                    + [
                        'by_grade' => $byGrade,
                        'grade_verdicts' => $breakdown === null ? [] : array_map(
                            self::verdict(...),
                            $this->fuel->gradeVerdicts($history, $breakdown, $zone),
                        ),
                    ];
            }
            $rows[] = ['vehicle_id' => $vehicle->id, 'currency' => $costs ? $currency : null, 'kinds' => $kinds];
        }

        return $this->head($period, $vehicles, []) + ['grade' => $grade?->value, 'by_vehicle' => $rows];
    }

    /**
     * Distance driven in the period, and the average per month and year
     * over the whole mileage log, per vehicle and in all.
     *
     * @return array<string, mixed>
     */
    public function mileage(User $user, ServerRequestInterface $request): array
    {
        [$period, $vehicles] = $this->scope($user, $request);
        $zone = $user->preferences->timeZone();

        $rows = [];
        $total = null;
        foreach ($vehicles as $vehicle) {
            $history = $this->odometer->history($vehicle);
            $km = PeriodDistance::km($history->readings, $period, $zone);
            if ($km !== null) {
                $total = Decimal::add($total ?? '0', $km);
            }
            $perMonth = $history->averageKmPerMonth();
            $latest = $history->latest();
            $rows[] = [
                'vehicle_id' => $vehicle->id,
                'distance_km' => Serializer::dec($km, Serializer::QUANTITY_SCALE),
                'average_km_per_month' => $perMonth === null ? null : Decimal::fromFloat($perMonth, 1),
                'average_km_per_year' => $perMonth === null ? null : Decimal::fromFloat($perMonth * 12, 1),
                'latest_odometer' => Serializer::dec($latest?->readingKm, Serializer::QUANTITY_SCALE),
                'latest_recorded_at' => Serializer::instant($latest?->recordedAt),
            ];
        }

        return $this->head($period, $vehicles, []) + [
            'distance_unit' => Serializer::DISTANCE_UNIT,
            'distance_km' => Serializer::dec($total, Serializer::QUANTITY_SCALE),
            'by_vehicle' => $rows,
        ];
    }

    /**
     * The report filter, the vehicles counted (costs visible) and those in
     * scope that are not, as the Reports page scopes them.
     *
     * @return array{0: ReportFilter, 1: list<Vehicle>, 2: list<Vehicle>}
     */
    private function costScope(User $user, ServerRequestInterface $request, ?CostGroup $group): array
    {
        $filter = $this->filter($user, $request, $group);
        $inScope = $filter->scope($this->vehicles->listFleet($user, true));
        $counted = array_values(array_filter(
            $inScope,
            fn (Vehicle $v): bool => $this->access->can($user, VehicleAbility::ViewCosts, $v),
        ));
        $excluded = array_values(array_filter($inScope, static fn (Vehicle $v): bool => !in_array($v, $counted, true)));

        return [$filter, $counted, $excluded];
    }

    /**
     * @return array{0: ReportPeriod, 1: list<Vehicle>}
     */
    private function scope(User $user, ServerRequestInterface $request): array
    {
        $filter = $this->filter($user, $request, null);

        return [$filter->period, $filter->scope($this->vehicles->listFleet($user, true))];
    }

    /**
     * The page's parameters, read strictly: anything given that can't be
     * read answers 400 rather than falling back as the page's form does.
     */
    private function filter(User $user, ServerRequestInterface $request, ?CostGroup $group): ReportFilter
    {
        $query = $request->getQueryParams();
        $range = QueryParams::code($request, 'range', ReportRange::class);
        foreach (['from', 'to'] as $name) {
            $value = $query[$name] ?? null;
            if ($value !== null && (!is_string($value) || LocalTime::parseDate($value) === null)) {
                throw ApiProblem::invalidParameter($name, 'a date (YYYY-MM-DD), with range=custom.');
            }
        }
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $vehicle = null;
        $raw = $query['vehicle'] ?? null;
        if ($raw !== null) {
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,18}$/', $raw) !== 1) {
                throw ApiProblem::invalidParameter('vehicle', 'a vehicle id.');
            }
            $vehicle = $this->reader->visibleVehicle($user, (int) $raw)
                ?? throw ApiProblem::notFound('There is no such vehicle.');
        }

        return new ReportFilter(
            ReportPeriod::fromQuery(['range' => $range?->value] + $query, $today),
            $vehicle?->id,
            QueryParams::flag($request, 'include_archived'),
            $group,
        );
    }

    /**
     * @param list<Vehicle> $counted
     * @param list<Vehicle> $excluded
     * @return array<string, mixed>
     */
    private function head(ReportPeriod $period, array $counted, array $excluded): array
    {
        return [
            'period' => [
                'range' => $period->range->value,
                'from' => Serializer::date($period->from),
                'to' => Serializer::date($period->to),
            ],
            'vehicles' => array_map(static fn (Vehicle $v): int => $v->id, $counted),
            'excluded' => array_map(static fn (Vehicle $v): int => $v->id, $excluded),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(CurrencyReport $section): array
    {
        return [
            'currency' => $section->currency,
            'total' => $section->total->toDecimal(Serializer::QUANTITY_SCALE),
            'entries' => $section->count,
            'distance_km' => Serializer::dec($section->distanceKm, Serializer::QUANTITY_SCALE),
            'cost_per_km' => Serializer::dec($section->costPerKm, self::SCALE),
            'average_per_month' => $section->averagePerMonth->toDecimal(Serializer::QUANTITY_SCALE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(FuelTotals $totals, EnergyKind $kind, bool $costs): array
    {
        return [
            'fill_ups' => $totals->fillUps,
            'volume' => Serializer::dec($totals->volume, Serializer::QUANTITY_SCALE),
            'volume_unit' => match ($kind) {
                EnergyKind::Liquid => 'l',
                EnergyKind::Electric => 'kwh',
                EnergyKind::Gas => 'kg',
            },
            ...($costs ? [
                'spend' => Serializer::dec($totals->spend, Serializer::QUANTITY_SCALE),
                'average_price_per_unit' => Serializer::dec($totals->pricePerUnit, Serializer::QUANTITY_SCALE),
            ] : []),
            'economy' => $totals->hasEconomy() ? [
                'distance_km' => Serializer::dec($totals->distanceKm, Serializer::QUANTITY_SCALE),
                'volume' => Serializer::dec($totals->measuredVolume, Serializer::QUANTITY_SCALE),
                'per_100km' => Decimal::divide(Decimal::multiply($totals->measuredVolume, '100', 6), $totals->distanceKm, 3),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function verdict(GradeVerdict $verdict): array
    {
        $percent = static fn (?PercentDifference $d): ?array => $d === null
            ? null
            : ['direction' => $d->direction, 'percent' => $d->percent];

        return [
            'grade' => $verdict->grade->value,
            'compared_with' => $verdict->reference->value,
            'status' => $verdict->status->value,
            'cost_per_distance' => $percent($verdict->cost()),
            'price' => $percent($verdict->price()),
            'fuel_used' => $percent($verdict->fuelUsed()),
        ];
    }
}
