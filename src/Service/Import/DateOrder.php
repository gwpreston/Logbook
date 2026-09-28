<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

/**
 * How the dates in an imported file are written.
 */
enum DateOrder: string
{
    /** 2026-09-27 (what Logbook exports). */
    case Iso = 'iso';
    /** 27/09/2026, 27.09.2026, 27-09-2026. */
    case DayFirst = 'dmy';
    /** 09/27/2026. */
    case MonthFirst = 'mdy';

    /**
     * The date as Y-m-d, or null when it is not written in this order.
     */
    public function normalise(string $value): ?string
    {
        $pattern = $this === self::Iso
            ? '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/'
            : '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/';
        if (preg_match($pattern, trim($value), $m) !== 1) {
            return null;
        }
        [$year, $month, $day] = match ($this) {
            self::Iso => [$m[1], $m[2], $m[3]],
            self::DayFirst => [$m[3], $m[2], $m[1]],
            self::MonthFirst => [$m[3], $m[1], $m[2]],
        };

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day)
            : null;
    }
}
