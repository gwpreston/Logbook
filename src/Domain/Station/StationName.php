<?php

declare(strict_types=1);

namespace Logbook\Domain\Station;

/**
 * A station name as typed, and the normalised form stations are matched by
 * (spec.md §7.33): trimmed, runs of whitespace collapsed to one space, and
 * case-folded. Done in PHP, never in SQL, so every engine and collation
 * groups alike. The upgrade migration (20261027100100) repeats it.
 */
final class StationName
{
    /** Trimmed, whitespace collapsed; the spelling kept as a name. */
    public static function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function normalise(string $text): string
    {
        return mb_strtolower(self::tidy($text), 'UTF-8');
    }
}
