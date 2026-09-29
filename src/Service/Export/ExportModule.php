<?php

declare(strict_types=1);

namespace Logbook\Service\Export;

use Logbook\Domain\Feature\Feature;

/**
 * The per-vehicle lists that export to (and import from) CSV (spec.md §7.7,
 * §7.13). The value is the URL segment: /vehicles/{id}/export/{module}.csv.
 * Tyres, tyre changes (spec.md §7.17) and valuations (§7.1) export only.
 */
enum ExportModule: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Documents = 'documents';
    case Expenses = 'expenses';
    case Tyres = 'tyres';
    case TyreChanges = 'tyre-changes';
    case Valuations = 'valuations';

    public function isImportable(): bool
    {
        return !in_array($this, [self::Tyres, self::TyreChanges, self::Valuations], true);
    }

    /**
     * The switchable module the list belongs to (null: always on).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fuel => Feature::Fuel,
            self::Maintenance => Feature::Maintenance,
            self::Documents => Feature::Compliance,
            self::Tyres, self::TyreChanges => Feature::Tyres,
            self::Odometer, self::Expenses, self::Valuations => null,
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
            self::Tyres, self::TyreChanges => 'tyres.index',
            self::Valuations => 'valuations.index',
        };
    }
}
