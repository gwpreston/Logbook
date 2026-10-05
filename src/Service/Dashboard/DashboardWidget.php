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
    /** Phase 24: what is wrong now (spec.md §7.24); core, first in new layouts. */
    case NeedsAttention = 'needs_attention';
    case Reminders = 'reminders';
    /** Phase 33.3: observations from existing figures (spec.md §7.8 *Insights*); core. */
    case Insights = 'insights';
    /** Phase 15: the 12-month forecast (spec.md §7.18); core. */
    case ComingUp = 'coming_up';
    case Spend = 'spend';
    case RecentFuel = 'recent_fuel';
    case Fleet = 'fleet';
    case Efficiency = 'efficiency';
    case Compliance = 'compliance';
    case Mileage = 'mileage';
    case RecentActivity = 'recent_activity';
    /** This tax year's business mileage and claim (Phase 22). */
    case BusinessMileage = 'business_mileage';
    /** Active finance agreements (Phase 29.2, spec.md §7.32): shown once a vehicle in view has one. */
    case Finance = 'finance';
    /** The cheapest listed fuel near a place (Phase 30.2, spec.md §7.34): only while a price provider is enabled. */
    case CheapestFuel = 'cheapest_fuel';
    /** Each vehicle's true cost per distance, ranked (Phase 32, spec.md §7.35); core. */
    case TrueCost = 'true_cost';

    /**
     * The module it shows, hidden with it (spec.md §7.10).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fleet, self::Mileage, self::RecentActivity, self::ComingUp, self::NeedsAttention, self::TrueCost,
                self::Insights => null,
            self::Reminders => Feature::Reminders,
            self::Spend => Feature::Reports,
            self::RecentFuel, self::Efficiency => Feature::Fuel,
            self::Compliance => Feature::Compliance,
            self::BusinessMileage => Feature::Trips,
            self::Finance => Feature::Finance,
            self::CheapestFuel => Feature::Stations,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fleet => 'garage',
            self::NeedsAttention => 'fact_check',
            self::Reminders => 'notifications',
            self::Insights => 'lightbulb',
            self::ComingUp => 'event_upcoming',
            self::Spend => 'payments',
            self::RecentFuel => 'local_gas_station',
            self::Efficiency => 'trending_up',
            self::Compliance => 'verified_user',
            self::Mileage => 'speed',
            self::BusinessMileage => 'route',
            self::RecentActivity => 'history',
            self::Finance => 'account_balance',
            self::CheapestFuel => 'price_check',
            self::TrueCost => 'toll',
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
