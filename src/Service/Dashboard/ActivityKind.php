<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

/**
 * What a line of the recent activity widget is (spec.md §7.8), and where it
 * is edited.
 */
enum ActivityKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Expense = 'expense';

    /**
     * The edit page (route name) and the name of its entry placeholder.
     *
     * @return array{0: string, 1: string}
     */
    public function editRoute(): array
    {
        return match ($this) {
            self::Fuel => ['fuel.edit', 'entry'],
            self::Odometer => ['odometer.edit', 'reading'],
            self::Maintenance => ['maintenance.edit', 'entry'],
            self::Document => ['compliance.edit', 'document'],
            self::Expense => ['expenses.edit', 'entry'],
        };
    }

    /**
     * Colour token of the icon (as the expense groups use them).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Fuel => 'c-fuel',
            self::Odometer => 'muted',
            self::Maintenance => 'c-maint',
            self::Document => 'c-ins',
            self::Expense => 'c-other',
        };
    }
}
