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
     * The date as Y-m-d, or null when it is not written in this order. A
     * date written year first is read under every order (#459): a
     * four-digit year first can't be mistaken for a day or a month.
     */
    public function normalise(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m) === 1) {
            [$year, $month, $day] = [$m[1], $m[2], $m[3]];
        } elseif ($this !== self::Iso && preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m) === 1) {
            [$year, $month, $day] = $this === self::DayFirst ? [$m[3], $m[2], $m[1]] : [$m[3], $m[1], $m[2]];
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day)
            : null;
    }
}
