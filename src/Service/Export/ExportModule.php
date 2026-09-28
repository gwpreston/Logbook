<?php

declare(strict_types=1);

namespace Logbook\Service\Export;

/**
 * The per-vehicle lists that export to CSV (spec.md §7.7). The value is the
 * URL segment: /vehicles/{id}/export/{module}.csv.
 */
enum ExportModule: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Documents = 'documents';
    case Expenses = 'expenses';
}
