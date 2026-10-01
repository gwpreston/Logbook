<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * The records an incident can link (spec.md §6 Incident *Links*).
 */
enum LinkKind: string
{
    case Maintenance = 'maintenance';
    case Expense = 'expense';
    case Tyre = 'tyre';

    /**
     * The table holding the record's `incident_id`.
     */
    public function table(): string
    {
        return match ($this) {
            self::Maintenance => 'maintenance_entries',
            self::Expense => 'expense_entries',
            self::Tyre => 'tyre_changes',
        };
    }
}
