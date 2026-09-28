<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Feature\Feature;

/**
 * The dashboard's widgets (spec.md §7.8). Case order is the default layout;
 * the value is the id stored in the saved layout.
 */
enum DashboardWidget: string
{
    case Fleet = 'fleet';
    case Reminders = 'reminders';
    case Spend = 'spend';
    case RecentFuel = 'recent_fuel';
    case Efficiency = 'efficiency';
    case Compliance = 'compliance';

    /**
     * The module it shows, hidden with it (spec.md §7.10).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fleet => null,
            self::Reminders => Feature::Reminders,
            self::Spend => Feature::Reports,
            self::RecentFuel, self::Efficiency => Feature::Fuel,
            self::Compliance => Feature::Compliance,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fleet => 'garage',
            self::Reminders => 'notifications',
            self::Spend => 'payments',
            self::RecentFuel => 'local_gas_station',
            self::Efficiency => 'trending_up',
            self::Compliance => 'verified_user',
        };
    }

    /**
     * Wide widgets span the whole row of the grid.
     */
    public function isWide(): bool
    {
        return $this === self::Fleet;
    }
}
