<?php

declare(strict_types=1);

namespace Logbook\Domain\Expense;

/**
 * Where a line of the cost ledger comes from. Each source belongs to exactly
 * one group, so no cost can be counted twice.
 */
enum CostSource: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
    case Expense = 'expense';
    /** Derived from a finance agreement (Phase 29.1, spec.md §7.32 *Costs*); counted as a finance expense. */
    case Finance = 'finance';

    public function group(): CostGroup
    {
        return match ($this) {
            self::Fuel => CostGroup::Fuel,
            self::Maintenance => CostGroup::Maintenance,
            self::Compliance => CostGroup::Compliance,
            self::Expense, self::Finance => CostGroup::Other,
        };
    }
}
