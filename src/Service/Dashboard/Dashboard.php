<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Service\Reminder\ReminderOverview;
use Logbook\Service\Report\Report;

/**
 * Everything the dashboard shows (spec.md §7.8). Only the visible widgets'
 * data is computed; the rest stays null or empty.
 */
final readonly class Dashboard
{
    /**
     * @param list<DashboardWidget> $available widgets of enabled modules, in layout order (hidden ones included)
     * @param list<FleetVehicle> $fleet active vehicles
     * @param list<RecentFill> $recentFuel newest first
     * @param list<VehicleEfficiency> $efficiency
     * @param list<VehicleCompliance> $compliance
     */
    public function __construct(
        public DashboardLayout $layout,
        public array $available,
        public int $archivedCount,
        public array $fleet = [],
        public ?ReminderOverview $reminders = null,
        public ?Report $spendThisMonth = null,
        public ?Report $spendLastMonth = null,
        public array $recentFuel = [],
        public array $efficiency = [],
        public array $compliance = [],
    ) {
    }

    /**
     * The widgets to render, in order.
     *
     * @return list<DashboardWidget>
     */
    public function visible(): array
    {
        return array_values(array_filter($this->available, fn (DashboardWidget $w): bool => !$this->layout->isHidden($w)));
    }

    public function isHidden(DashboardWidget $widget): bool
    {
        return $this->layout->isHidden($widget);
    }

    public function hasVehicles(): bool
    {
        return $this->fleet !== [];
    }
}
