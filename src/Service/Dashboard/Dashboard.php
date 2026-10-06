<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Ai\Insights\AiInsightSet;
use Logbook\Service\Trip\ClaimReport;
use Logbook\Service\Attention\AttentionReport;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\FuelPrices\CheapestFuelWidget;
use Logbook\Service\Forecast\Forecast;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\Insights\Insight;
use Logbook\Service\Reminder\CalendarMonth;
use Logbook\Service\Reminder\ReminderOverview;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\TrueCostWidget;
use Logbook\Service\Vehicle\VehicleSnapshot;

/**
 * Everything the dashboard shows (spec.md §7.8). Only the visible widgets'
 * data is computed; the rest stays null or empty. With a vehicle selected
 * every widget is about that vehicle only.
 */
final readonly class Dashboard
{
    /**
     * @param list<DashboardWidget> $available widgets of enabled modules, in layout order (hidden ones included)
     * @param list<Vehicle> $vehicles every active vehicle (the filter chips)
     * @param list<VehicleSnapshot> $fleet the "your vehicles" tiles
     * @param list<RecentFill> $recentFuel newest first
     * @param list<VehicleEfficiency> $efficiency
     * @param list<VehicleCompliance> $compliance
     * @param list<ActivityItem> $activity newest first
     * @param list<AgreementView>|null $finance
     * @param list<Insight>|null $insights
     */
    public function __construct(
        public DashboardLayout $layout,
        public array $available,
        public int $archivedCount,
        public array $vehicles = [],
        public ?Vehicle $selected = null,
        public ?PinnedVehicle $pinned = null,
        public array $fleet = [],
        public ?ReminderOverview $reminders = null,
        /** The Calendar widget's month of open reminders (Phase 34.3); null while hidden. */
        public ?CalendarMonth $calendar = null,
        public ?Report $spendThisMonth = null,
        public ?Report $spendLastMonth = null,
        /** Phase 34.2; null while hidden or with no vehicle in view whose costs the viewer may see. */
        public ?ExpenseBreakdown $expenseBreakdown = null,
        /** Phase 34.2; null as the breakdown. */
        public ?MonthlySpend $monthlySpend = null,
        public array $recentFuel = [],
        public array $efficiency = [],
        public array $compliance = [],
        public ?MileageSummary $mileage = null,
        public array $activity = [],
        public ?Forecast $comingUp = null,
        /** The signed-in user's claim for this tax year (Phase 22). */
        public ?ClaimReport $businessMileage = null,
        /** Needs attention across the filter (Phase 24); null while the widget is hidden. */
        public ?AttentionReport $attention = null,
        /** Active agreements in view, for those who may see their finance (Phase 29.2); null when not built. */
        public ?array $finance = null,
        /** The cheapest fuel near a place (Phase 30.2); null while hidden or prices are off. */
        public ?CheapestFuelWidget $cheapestFuel = null,
        /** Each vehicle's true cost, ranked (Phase 32); null while hidden. */
        public ?TrueCostWidget $trueCost = null,
        /** The first insights (Phase 33.3, spec.md §7.8); null while hidden. */
        public ?array $insights = null,
        /** Today's AI insights joining them (Phase 33.4, spec.md §7.26); null while hidden or AI is off. */
        public ?AiInsightSet $aiInsights = null,
    ) {
    }

    /**
     * The widgets to render, in order. "Your vehicles" makes way for the
     * pinned card while one vehicle is selected.
     *
     * @return list<DashboardWidget>
     */
    public function visible(): array
    {
        return array_values(array_filter(
            $this->available,
            fn (DashboardWidget $w): bool => !$this->layout->isHidden($w)
                && !($this->selected !== null && $w === DashboardWidget::Fleet)
                // Nothing about finance shows until a vehicle in view has an agreement (spec.md §7.32 *Module*).
                && !($w === DashboardWidget::Finance && $this->finance === [])
                // Gone when no vehicle in view has costs the viewer may see (spec.md §7.8 *Both spend widgets*).
                && !($w === DashboardWidget::ExpenseBreakdown && $this->expenseBreakdown === null)
                && !($w === DashboardWidget::MonthlyExpenses && $this->monthlySpend === null),
        ));
    }

    public function isHidden(DashboardWidget $widget): bool
    {
        return $this->layout->isHidden($widget);
    }

    public function hasVehicles(): bool
    {
        return $this->vehicles !== [];
    }

    /**
     * The vehicle filter is offered from two active vehicles on.
     */
    public function hasFilter(): bool
    {
        return count($this->vehicles) > 1;
    }
}
