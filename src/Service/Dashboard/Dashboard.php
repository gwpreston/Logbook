<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\Reminder\ReminderOverview;
use Logbook\Service\Report\Report;
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
        public ?Report $spendThisMonth = null,
        public ?Report $spendLastMonth = null,
        public array $recentFuel = [],
        public array $efficiency = [],
        public array $compliance = [],
        public ?MileageSummary $mileage = null,
        public array $activity = [],
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
                && !($this->selected !== null && $w === DashboardWidget::Fleet),
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
