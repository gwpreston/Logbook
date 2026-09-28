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
     * Colour token in app.css, shared by the charts and the legends.
     */
    public function color(): string
    {
        return match ($this) {
            self::Fuel => 'c-fuel',
            self::Maintenance => 'c-maint',
            self::Compliance => 'c-ins',
            self::Other => 'c-other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fuel => 'local_gas_station',
            self::Maintenance => 'build',
            self::Compliance => 'verified_user',
            self::Other => 'payments',
        };
    }
}
