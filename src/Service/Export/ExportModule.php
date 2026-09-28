<?php

declare(strict_types=1);

namespace Logbook\Service\Export;

use Logbook\Domain\Feature\Feature;

/**
 * The per-vehicle lists that export to (and import from) CSV (spec.md §7.7,
 * §7.13). The value is the URL segment: /vehicles/{id}/export/{module}.csv.
 */
enum ExportModule: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Documents = 'documents';
    case Expenses = 'expenses';

    /**
     * The switchable module the list belongs to (null: always on).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fuel => Feature::Fuel,
            self::Maintenance => Feature::Maintenance,
            self::Documents => Feature::Compliance,
            self::Odometer, self::Expenses => null,
        };
    }

    /**
     * The vehicle tab listing it.
     */
    public function indexRoute(): string
    {
        return match ($this) {
            self::Fuel => 'fuel.index',
            self::Odometer => 'odometer.index',
            self::Maintenance => 'maintenance.index',
            self::Documents => 'compliance.index',
            self::Expenses => 'expenses.index',
        };
    }
}
