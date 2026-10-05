<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\FuelPrices\FillUpComparisons;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\VehicleCost;
use Logbook\Service\Station\StationService;
use Logbook\Service\Trip\ClaimReport;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;

/**
 * The dashboard's *Insights* (spec.md §7.8): short observations worked out
 * from figures other pages already show, never by a model. In order:
 * shopping around (the Fuel tab's figure), business mileage (the claim
 * report), cheapest to run (the reports' cost per distance over the last
 * 12 months) and equity (the Finance tab). Each appears only when its
 * module is on and the viewer may see the figure where it is shown.
 */
final readonly class InsightsService
{
    /** Each vehicle compared for *cheapest to run* drove at least this far in the 12 months. */
    public const int CHEAPEST_MIN_KM = 500;

    public function __construct(
        private FeatureToggles $features,
        private VehicleAccess $access,
        private StationService $stations,
        private FillUpComparisons $comparisons,
        private ClaimReportService $claims,
        private ReportService $reports,
        private FinanceService $finance,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * The insights for the vehicles in view, in order, stopping once
     * $limit are found (the later kinds are then never worked out).
     *
     * @param list<Vehicle> $vehicles the active vehicles in view
     * @param bool $allVehicles the dashboard shows *All vehicles* (no vehicle chosen)
     * @param ClaimReport|null $claim this tax year's claim, when already built
     * @param list<AgreementView>|null $agreements the active agreements in view the viewer may see, when already built
     * @return list<Insight>
     */
    public function forVehicles(
        User $user,
        array $vehicles,
        bool $allVehicles,
        DateTimeImmutable $today,
        ?int $limit = null,
        ?ClaimReport $claim = null,
        ?array $agreements = null,
    ): array {
        $kinds = [
            fn (): array => $this->shoppingAround($user, $vehicles),
            fn (): array => $this->businessMileage($user, $vehicles, $allVehicles, $today, $claim),
            fn (): array => $allVehicles ? $this->cheapestToRun($user, $vehicles, $today) : [],
            fn (): array => $this->equity($user, $vehicles, $agreements),
        ];
        $insights = [];
        foreach ($kinds as $kind) {
            if ($limit !== null && count($insights) >= $limit) {
                break;
            }
            array_push($insights, ...$kind());
        }

        return $limit === null ? $insights : array_slice($insights, 0, $limit);
    }

    /**
     * The Fuel tab's *Shopping around* (spec.md §7.34), per vehicle, when
     * better off; as the tab: Fuel stations on and `ViewCosts`.
     *
     * @param list<Vehicle> $vehicles
     * @return list<Insight>
     */
    private function shoppingAround(User $user, array $vehicles): array
    {
        if (!$this->features->isEnabled(Feature::Fuel) || !$this->stations->enabled()) {
            return [];
        }
        $insights = [];
        foreach ($vehicles as $vehicle) {
            if (!$this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
                continue;
            }
            $shopping = $this->comparisons->shoppingAround($user, $vehicle);
            if ($shopping === null || Decimal::compare($shopping->total, '0') <= 0) {
                continue;
            }
            $insights[] = new Insight(
                InsightKind::ShoppingAround,
                InsightTone::Good,
                'insights.shopping_around.title',
                ['amount' => $this->formatter->money($shopping->total, $shopping->currency)],
                $shopping->withDistance ? 'insights.shopping_around.body' : 'insights.shopping_around.body_before_driving',
                ['count' => $shopping->fillUps, 'vehicle' => $vehicle->name()],
                'fuel.index',
                ['id' => $vehicle->id],
            );
        }

        return $insights;
    }

    /**
     * The signed-in user's own claim this tax year (spec.md §7.23), as the
     * *Business mileage* widget: for the chosen vehicle when there is one.
     *
     * @param list<Vehicle> $vehicles
     * @return list<Insight>
     */
    private function businessMileage(
        User $user,
        array $vehicles,
        bool $allVehicles,
        DateTimeImmutable $today,
        ?ClaimReport $claim,
    ): array {
        if (!$this->features->isEnabled(Feature::Trips)) {
            return [];
        }
        $claim ??= $this->claims->thisYear(
            $user,
            $today,
            $allVehicles ? [] : array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
        $amounts = [];
        foreach ($claim->totals as $totals) {
            if (Decimal::compare($totals->approvedAmount(), '0') > 0) {
                $amounts[] = $this->formatter->money($totals->approvedAmount(), $totals->currency);
            }
        }
        $distance = $claim->distanceKm();
        if ($amounts === [] || Decimal::compare($distance, '0') <= 0) {
            return [];
        }

        return [new Insight(
            InsightKind::BusinessMileage,
            InsightTone::Good,
            'insights.business_mileage.title',
            ['amount' => implode(' · ', $amounts)],
            'insights.business_mileage.body',
            [
                'distance' => $this->formatter->distance($distance),
                'since' => $this->formatter->date($claim->filter->from, IntlDateFormatter::MEDIUM),
            ],
            'trips.claim',
        )];
    }

    /**
     * The lowest running cost per distance over the last 12 months (the
     * reports' figure, spec.md §7.7) against the highest, among active
     * vehicles that each drove at least 500 km, in one currency: the one
     * most of them use (ties: the viewer's own). Never converted.
     *
     * @param list<Vehicle> $vehicles
     * @return list<Insight>
     */
    private function cheapestToRun(User $user, array $vehicles, DateTimeImmutable $today): array
    {
        if (!$this->features->isEnabled(Feature::Reports)) {
            return [];
        }
        $costly = array_values(array_filter(
            $vehicles,
            fn (Vehicle $v): bool => $this->access->can($user, VehicleAbility::ViewCosts, $v),
        ));
        if (count($costly) < 2) {
            return [];
        }
        $report = $this->reports->forVehicles(
            $user,
            new ReportFilter(ReportPeriod::preset(ReportRange::TwelveMonths, $today)),
            $costly,
        );

        /** @var array<string, list<VehicleCost>> $byCurrency */
        $byCurrency = [];
        foreach ($report->currencies as $section) {
            foreach ($section->vehicles as $cost) {
                if (
                    $cost->costPerKm !== null && $cost->distanceKm !== null
                    && Decimal::compare($cost->distanceKm, (string) self::CHEAPEST_MIN_KM) >= 0
                ) {
                    $byCurrency[$section->currency][] = $cost;
                }
            }
        }
        $currency = self::mostUsed($byCurrency, $user->preferences->currency);
        $costs = $currency === null ? [] : $byCurrency[$currency];
        if (count($costs) < 2) {
            return [];
        }
        usort($costs, static fn (VehicleCost $a, VehicleCost $b): int
            => Decimal::compare((string) $a->costPerKm, (string) $b->costPerKm) ?: $a->vehicle->id <=> $b->vehicle->id);
        $cheapest = $costs[0];
        $dearest = $costs[count($costs) - 1];
        if (Decimal::compare((string) $cheapest->costPerKm, (string) $dearest->costPerKm) >= 0) {
            return [];
        }

        return [new Insight(
            InsightKind::CheapestToRun,
            InsightTone::Neutral,
            'insights.cheapest_to_run.title',
            ['vehicle' => $cheapest->vehicle->name()],
            'insights.cheapest_to_run.body',
            [
                'rate' => $this->formatter->perDistance($cheapest->costPerKm, $currency),
                'other_rate' => $this->formatter->perDistance($dearest->costPerKm, $currency),
                'other' => $dearest->vehicle->name(),
            ],
            'reports.index',
        )];
    }

    /**
     * The currency with the most vehicles; a tie goes to the viewer's own
     * currency, else to the first in alphabetical order.
     *
     * @param array<string, list<VehicleCost>> $byCurrency
     */
    private static function mostUsed(array $byCurrency, string $own): ?string
    {
        if ($byCurrency === []) {
            return null;
        }
        $counts = array_map('count', $byCurrency);
        $most = max($counts);
        $tied = array_keys(array_filter($counts, static fn (int $n): bool => $n === $most));
        if (in_array($own, $tied, true)) {
            return $own;
        }
        sort($tied);

        return $tied[0];
    }

    /**
     * Equity on an active HP or PCP agreement with a valuation from the
     * last 12 months (the Finance tab's figure, spec.md §7.32): never an
     * estimate of the value. Finance on, `Manage` and `ViewCosts`.
     *
     * @param list<Vehicle> $vehicles
     * @param list<AgreementView>|null $agreements
     * @return list<Insight>
     */
    private function equity(User $user, array $vehicles, ?array $agreements): array
    {
        if (!$this->features->isEnabled(Feature::Finance)) {
            return [];
        }
        $agreements ??= array_values(array_filter(array_map(
            fn (Vehicle $vehicle): ?AgreementView => $this->finance->activeView($user, $vehicle),
            $vehicles,
        )));
        $names = [];
        foreach ($vehicles as $vehicle) {
            $names[$vehicle->id] = $vehicle->name();
        }

        $insights = [];
        foreach ($agreements as $view) {
            $agreement = $view->agreement;
            $equity = $view->figures->equity;
            $settlement = $view->figures->settlement;
            if (
                !in_array($agreement->type(), [AgreementType::Hp, AgreementType::Pcp], true)
                || $equity === null || $settlement === null || !isset($names[$agreement->vehicleId])
            ) {
                continue;
            }
            $positive = $equity->isPositive();
            $insights[] = new Insight(
                InsightKind::Equity,
                $positive ? InsightTone::Good : InsightTone::Watch,
                $positive ? 'insights.equity.title' : 'insights.equity.title_negative',
                [
                    'vehicle' => $names[$agreement->vehicleId],
                    'amount' => $this->formatter->money($equity->magnitude(), null, 0),
                ],
                $settlement->isEstimate() ? 'insights.equity.body' : 'insights.equity.body_quote',
                [
                    'value' => $this->formatter->money($equity->valuation, null, 0),
                    'settlement' => $this->formatter->money($settlement->amount, null, 0),
                ],
                'finance.index',
                ['id' => $agreement->vehicleId],
            );
        }

        return $insights;
    }
}
