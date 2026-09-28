<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderOverview;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Assembles the dashboard (spec.md §7.8) for the owner's layout, computing
 * only what the visible widgets show. Archived vehicles never appear.
 */
final readonly class DashboardService
{
    public const int RECENT_FILLS = 5;
    public const int EFFICIENCY_MONTHS = 12;

    public function __construct(
        private DashboardLayoutStore $layouts,
        private FeatureToggles $features,
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private ReminderService $reminders,
        private ReminderSettingsStore $reminderSettings,
        private ReportService $reports,
        private FuelService $fuel,
        private ComplianceService $compliance,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Hidden widgets get no data: customise mode shows them folded up.
     */
    public function build(User $user): Dashboard
    {
        $layout = $this->layouts->load($user->id);
        $enabled = $this->features->all();
        $available = array_values(array_filter(
            $layout->order,
            static fn (DashboardWidget $w): bool => $w->feature() === null || $enabled[$w->feature()->value],
        ));
        $show = static fn (DashboardWidget $w): bool => in_array($w, $available, true) && !$layout->isHidden($w);

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $active = $this->vehicles->listFleet($user);
        $counts = $this->vehicles->counts($user);

        $overview = $show(DashboardWidget::Reminders) || ($show(DashboardWidget::Fleet) && $enabled['reminders'])
            ? $this->reminders->overview($user)
            : null;

        [$thisMonth, $lastMonth] = $show(DashboardWidget::Spend) && $active !== []
            ? $this->spend($user, $active, $today)
            : [null, null];

        $fuel = $show(DashboardWidget::RecentFuel) || $show(DashboardWidget::Efficiency)
            ? $this->fuelHistories($active)
            : [];

        return new Dashboard(
            layout: $layout,
            available: $available,
            archivedCount: $counts['archived'],
            fleet: $this->fleet($active, $overview),
            reminders: $show(DashboardWidget::Reminders) ? $overview : null,
            spendThisMonth: $thisMonth,
            spendLastMonth: $lastMonth,
            recentFuel: $show(DashboardWidget::RecentFuel) ? $this->recentFuel($user, $fuel) : [],
            efficiency: $show(DashboardWidget::Efficiency) ? $this->efficiency($fuel, $today) : [],
            compliance: $show(DashboardWidget::Compliance) ? $this->compliance($user, $active, $today) : [],
        );
    }

    /**
     * @param list<Vehicle> $active
     * @return list<FleetVehicle>
     */
    private function fleet(array $active, ?ReminderOverview $overview): array
    {
        $next = [];
        foreach ($overview === null ? [] : $overview->open as $entry) {
            $next[$entry->vehicle->id] ??= $entry;
        }

        return array_map(fn (Vehicle $v): FleetVehicle => new FleetVehicle(
            $v,
            $this->odometer->history($v)->latest(),
            $next[$v->id] ?? null,
        ), $active);
    }

    /**
     * This month so far and the whole of last month, over the active fleet.
     *
     * @param list<Vehicle> $active
     * @return array{0: Report, 1: Report}
     */
    private function spend(User $user, array $active, DateTimeImmutable $today): array
    {
        $thisMonth = ReportPeriod::preset(ReportRange::ThisMonth, $today);
        $firstOfThis = $thisMonth->from ?? $today;
        $lastMonth = new ReportPeriod(
            ReportRange::Custom,
            LocalTime::addMonths($firstOfThis, -1),
            $firstOfThis->modify('-1 day'),
        );

        [$current, $previous] = $this->reports->compare($user, $active, [
            new ReportFilter($thisMonth),
            new ReportFilter($lastMonth),
        ]);

        return [$current, $previous];
    }

    /**
     * @param list<Vehicle> $active
     * @return list<array{vehicle: Vehicle, history: FuelHistory}>
     */
    private function fuelHistories(array $active): array
    {
        return array_map(fn (Vehicle $v): array => ['vehicle' => $v, 'history' => $this->fuel->history($v)], $active);
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
            foreach (array_slice($history->newestFirst(), 0, self::RECENT_FILLS) as $fill) {
                $fills[] = new RecentFill($vehicle, $fill, $currency);
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
     * @param list<Vehicle> $active
     * @return list<VehicleCompliance>
     */
    private function compliance(User $user, array $active, DateTimeImmutable $today): array
    {
        $lead = $this->reminderSettings->reminderPreferences($user->id)->documentDays;

        return array_map(fn (Vehicle $v): VehicleCompliance => new VehicleCompliance(
            $v,
            array_values(array_filter(
                $this->compliance->states($v, $today, $lead),
                static fn (DocumentState $s): bool => $s->status->isCurrent(),
            )),
        ), $active);
    }
}
