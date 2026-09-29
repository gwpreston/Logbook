<?php

declare(strict_types=1);

namespace Logbook\Repository;

/**
 * The entry tables the activity feed pages by date (spec.md §7.16), for
 * ActivityDateRepository. Fill-ups and readings are dated by a UTC instant,
 * the rest by a calendar date.
 */
enum DatedSource
{
    case Fuel;
    case Reading;
    case Maintenance;
    case Expense;
    case TyreChange;
    case Valuation;

    public function isInstant(): bool
    {
        return $this === self::Fuel || $this === self::Reading;
    }
}
