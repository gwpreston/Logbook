<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Attention\AttentionList;
use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\FuelPrices\CheapestFuelWidgets;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\Reminder\DueCounter;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderOverview;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Service\Vehicle\VehicleSnapshot;
use Logbook\Service\Vehicle\VehicleSnapshots;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Assembles the dashboard (spec.md §7.8) for the owner's layout, computing
 * only what the visible widgets show. Archived vehicles never appear. With
 * a vehicle selected (the filter chips) every widget covers that vehicle
 * only and the pinned vehicle card replaces "your vehicles".
 */
final readonly class DashboardService
{
    public const int RECENT_FILLS = 5;
    public const int EFFICIENCY_MONTHS = 12;

    public function __construct(
        private DashboardLayoutStore $layouts,
        private FeatureToggles $features,
        private VehicleService $vehicles,
        private VehicleSnapshots $snapshots,
        private OdometerReadingRepository $readings,
        private ReminderService $reminders,
        private ReminderSettingsStore $reminderSettings,
        private DueCounter $dueCounter,
        private ReportService $reports,
        private FuelService $fuel,
        private ComplianceService $compliance,
        private ActivityFeed $activity,
        private ClockInterface $clock,
        private ComingUp $comingUp,
        private VehicleAccess $access,
        private ClaimReportService $claims,
        private AttentionList $attention,
        private FinanceService $financeService,
        private FuelPriceConfig $fuelPrices,
        private CheapestFuelWidgets $cheapestFuel,
    ) {
    }

    /**
     * Hidden widgets get no data: customise mode shows them folded up.
     *
     * @param int|null $vehicleId the selected vehicle; unknown or archived means the fleet
     */
    public function build(User $user, ?int $vehicleId = null): Dashboard
    {
        $layout = $this->layouts->load($user->id);
        $enabled = $this->features->all();
        $available = array_values(array_filter(
            $layout->order,
            fn (DashboardWidget $w): bool => ($w->feature() === null || $enabled[$w->feature()->value])
                // Listed only while a price provider is enabled (spec.md §7.34).
                && ($w !== DashboardWidget::CheapestFuel || $this->fuelPrices->enabled()),
        ));

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $active = $this->vehicles->listFleet($user);
        $selected = self::find($active, $vehicleId);
        $scope = $selected !== null ? [$selected] : $active;
        $show = static fn (DashboardWidget $w): bool => in_array($w, $available, true)
            && !$layout->isHidden($w)
            && !($selected !== null && $w === DashboardWidget::Fleet);

        // Read (and so sync) the reminders before counting what is due.
        $overview = $enabled['reminders'] && ($show(DashboardWidget::Reminders) || $selected !== null)
            ? self::only($this->reminders->overview($user), $selected)
            : null;

        // Spend counts only the vehicles whose costs the user may see (spec.md §5 Costs).
        $costly = array_values(array_filter(
            $scope,
            fn (Vehicle $v): bool => $this->access->can($user, VehicleAbility::ViewCosts, $v),
        ));
        [$thisMonth, $lastMonth] = $show(DashboardWidget::Spend) && $costly !== []
            ? $this->spend($user, $costly, $today)
            : [null, null];

        $fuel = $show(DashboardWidget::RecentFuel) || $show(DashboardWidget::Efficiency) || $selected !== null
            ? $this->fuelHistories($scope)
            : [];
        $efficiency = $fuel !== [] ? $this->efficiency($fuel, $today) : [];

        $needsCounts = $show(DashboardWidget::Fleet) || $selected !== null;
        $counts = $needsCounts ? $this->dueCounter->counts($user) : null;

        // Needs attention (spec.md §7.24): one pass over the filter, for the
        // widget and the tiles' markers. The reminders were synced above if
        // they were read; *Coming up* is shared when its widget shows too.
        $comingUp = $show(DashboardWidget::ComingUp) ? $this->comingUp->forecast($user, $scope) : null;
        $attention = $show(DashboardWidget::NeedsAttention) || $show(DashboardWidget::Fleet)
            ? $this->attention->forVehicles($user, $scope, sync: $overview === null, forecast: $comingUp)
            : null;

        return new Dashboard(
            layout: $layout,
            available: $available,
            archivedCount: $this->vehicles->counts($user)['archived'],
            vehicles: $active,
            selected: $selected,
            pinned: $selected !== null && $counts !== null
                ? $this->pinned($user, $selected, $this->snapshots->of([$selected], $counts)[0], $efficiency, $overview, $today)
                : null,
            fleet: $show(DashboardWidget::Fleet) && $counts !== null
                ? $this->snapshots->of($active, $counts, $attention?->counts() ?? [])
                : [],
            reminders: $show(DashboardWidget::Reminders) ? $overview : null,
            spendThisMonth: $thisMonth,
            spendLastMonth: $lastMonth,
            recentFuel: $show(DashboardWidget::RecentFuel) ? $this->recentFuel($user, $fuel) : [],
            efficiency: $show(DashboardWidget::Efficiency) ? $efficiency : [],
            compliance: $show(DashboardWidget::Compliance) ? $this->compliance($user, $scope, $today) : [],
            mileage: $show(DashboardWidget::Mileage) ? $this->mileage($user, $scope, $today) : null,
            activity: $show(DashboardWidget::RecentActivity) ? $this->activity->latest($user, $scope) : [],
            comingUp: $comingUp,
            businessMileage: $show(DashboardWidget::BusinessMileage)
                ? $this->claims->thisYear($user, $today, $selected !== null ? [$selected->id] : [])
                : null,
            attention: $show(DashboardWidget::NeedsAttention) ? $attention : null,
            finance: $show(DashboardWidget::Finance) ? $this->finance($user, $scope) : null,
            cheapestFuel: $show(DashboardWidget::CheapestFuel) ? $this->cheapestFuel->build($user, $selected) : null,
        );
    }

    /**
     * The active agreement of each vehicle in view the user may see the
     * finance of (spec.md §7.32 *Dashboard widget*), in the fleet's order.
     *
     * @param list<Vehicle> $scope
     * @return list<AgreementView>
     */
    private function finance(User $user, array $scope): array
    {
        return array_values(array_filter(array_map(
            fn (Vehicle $vehicle): ?AgreementView => $this->financeService->activeView($user, $vehicle),
            $scope,
        )));
    }

    /**
     * @param list<Vehicle> $active
     */
    private static function find(array $active, ?int $vehicleId): ?Vehicle
    {
        foreach ($active as $vehicle) {
            if ($vehicle->id === $vehicleId) {
                return $vehicle;
            }
        }

        return null;
    }

    /**
     * The overview narrowed to one vehicle (unchanged for the fleet).
     */
    private static function only(ReminderOverview $overview, ?Vehicle $vehicle): ReminderOverview
    {
        if ($vehicle === null) {
            return $overview;
        }
        $mine = static fn (ReminderEntry $e): bool => $e->vehicle->id === $vehicle->id;

        return new ReminderOverview(
            array_values(array_filter($overview->open, $mine)),
            array_values(array_filter($overview->closed, $mine)),
            $overview->today,
        );
    }

    /**
     * @param list<VehicleEfficiency> $efficiency the vehicle's rows (12 months)
     */
    private function pinned(
        User $user,
        Vehicle $vehicle,
        VehicleSnapshot $snapshot,
        array $efficiency,
        ?ReminderOverview $overview,
        DateTimeImmutable $today,
    ): PinnedVehicle {
        $kind = $vehicle->data->fuelType->primaryKind();
        $economy = null;
        foreach ($efficiency as $row) {
            if ($row->vehicle->id === $vehicle->id && ($economy === null || $row->kind === $kind)) {
                $economy = $row;
            }
        }

        $report = $this->access->can($user, VehicleAbility::ViewCosts, $vehicle) ? $this->reports->forVehicles(
            $user,
            new ReportFilter(ReportPeriod::preset(ReportRange::TwelveMonths, $today), $vehicle->id),
            [$vehicle],
        ) : null;

        return new PinnedVehicle(
            $snapshot,
            $economy,
            $report?->currencies[0] ?? null,
            $overview?->open[0] ?? null,
        );
    }

    /**
     * @param list<Vehicle> $vehicles
     */
    private function mileage(User $user, array $vehicles, DateTimeImmutable $today): MileageSummary
    {
        return MileageSummary::of(
            array_map(fn (Vehicle $v): array => $this->readings->listForVehicle($v->id), $vehicles),
            $today,
            $user->preferences->timeZone(),
        );
    }

    /**
     * This month so far and the whole of last month.
     *
     * @param list<Vehicle> $vehicles
     * @return array{0: Report, 1: Report}
     */
    private function spend(User $user, array $vehicles, DateTimeImmutable $today): array
    {
        $thisMonth = ReportPeriod::preset(ReportRange::ThisMonth, $today);
        $firstOfThis = $thisMonth->from ?? $today;
        $lastMonth = new ReportPeriod(
            ReportRange::Custom,
            LocalTime::addMonths($firstOfThis, -1),
            $firstOfThis->modify('-1 day'),
        );

        [$current, $previous] = $this->reports->compare($user, $vehicles, [
            new ReportFilter($thisMonth),
            new ReportFilter($lastMonth),
        ]);

        return [$current, $previous];
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<array{vehicle: Vehicle, history: FuelHistory}>
     */
    private function fuelHistories(array $vehicles): array
    {
        if (!$this->features->isEnabled(Feature::Fuel)) {
            return [];
        }

        return array_map(fn (Vehicle $v): array => ['vehicle' => $v, 'history' => $this->fuel->history($v)], $vehicles);
    }

    /**
     * @param list<array{vehicle: Vehicle, history: FuelHistory}> $histories
     * @return list<RecentFill>
     */
    private function recentFuel(User $user, array $histories): array
    {
        $fills = [];
        foreach ($histories as ['vehicle' => $vehicle, 'history' => $history]) {
            $currency = $this->vehicles->currencyFor($user, $vehicle);
            $checks = $this->fuel->checks($history);
            foreach (array_slice($history->newestFirst(), 0, self::RECENT_FILLS) as $fill) {
                $check = $checks->for($fill->entry->id);
                $fills[] = new RecentFill($vehicle, $fill, $currency, $check?->isFlagged() === true ? $check : null);
            }
        }
        usort($fills, static fn (RecentFill $a, RecentFill $b): int
            => ($b->fill->entry->data->filledAt <=> $a->fill->entry->data->filledAt)
                ?: $b->fill->entry->id <=> $a->fill->entry->id);

        return array_slice($fills, 0, self::RECENT_FILLS);
    }

    /**
     * Each vehicle's measured economy per kind of energy over the last 12
     * months (by when each measured segment ended).
     *
     * @param list<array{vehicle: Vehicle, history: FuelHistory}> $histories
     * @return list<VehicleEfficiency>
     */
    private function efficiency(array $histories, DateTimeImmutable $today): array
    {
        $since = LocalTime::addMonths($today, -self::EFFICIENCY_MONTHS);
        $rows = [];
        foreach ($histories as ['vehicle' => $vehicle, 'history' => $history]) {
            foreach (EnergyKind::cases() as $kind) {
                if ($history->summary($kind) === null) {
                    continue;
                }
                $measured = array_values(array_filter(
                    $history->measured($kind),
                    static fn (FillEconomy $f): bool => $f->segment !== null && $f->segment->endedAt >= $since,
                ));
                $rows[] = new VehicleEfficiency($vehicle, $kind, $measured);
            }
        }

        return $rows;
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<VehicleCompliance>
     */
    private function compliance(User $user, array $vehicles, DateTimeImmutable $today): array
    {
        // Each vehicle's owner's lead time, as its reminders use (Phase 19).
        $leads = [];
        foreach ($vehicles as $vehicle) {
            $leads[$vehicle->userId] ??= $this->reminderSettings->reminderPreferences($vehicle->userId)->documentDays;
        }

        return array_map(fn (Vehicle $v): VehicleCompliance => new VehicleCompliance(
            $v,
            array_values(array_filter(
                $this->compliance->states($v, $today, $leads[$v->userId] ?? 0),
                static fn (DocumentState $s): bool => $s->status->isCurrent(),
            )),
        ), $vehicles);
    }
}
