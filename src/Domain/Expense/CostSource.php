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

    public function group(): CostGroup
    {
        return match ($this) {
            self::Fuel => CostGroup::Fuel,
            self::Maintenance => CostGroup::Maintenance,
            self::Compliance => CostGroup::Compliance,
            self::Expense => CostGroup::Other,
        };
    }
}
