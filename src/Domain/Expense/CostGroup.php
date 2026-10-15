<?php

declare(strict_types=1);

namespace Logbook\Domain\Expense;

/**
 * The groups every cost is reported under (spec.md §7.7), in display order.
 */
enum CostGroup: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
    case Other = 'other';
    /**
     * *Modifications* (Phase 50.1, spec.md §7.39, #419): build spend. In
     * totals and the breakdown, never in a running cost per distance or per month.
     */
    case Build = 'build';

    /**
     * Colour token in app.css, shared by the charts and the legends.
     */
    public function color(): string
    {
        return match ($this) {
            self::Fuel => 'c-fuel',
            self::Maintenance => 'c-maint',
            self::Compliance => 'c-ins',
            self::Other => 'c-other',
            self::Build => 'c-build',
        };
    }

    /**
     * Whether the group is a running cost: what the car costs to run, the
     * question every per-distance and per-month figure answers (spec.md
     * §7.7). Build spend is a choice, not a running cost (#419).
     */
    public function isRunning(): bool
    {
        return $this !== self::Build;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fuel => 'local_gas_station',
            self::Maintenance => 'build',
            self::Compliance => 'verified_user',
            self::Other => 'payments',
            self::Build => 'handyman',
        };
    }
}
