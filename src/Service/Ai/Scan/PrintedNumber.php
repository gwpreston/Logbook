<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use NumberFormatter;

/**
 * An amount, reading or quantity as printed ("£184.50", "48,120 miles",
 * "45,12 l"), as a canonical decimal string (spec.md §7.27 *Checking
 * values*). Currency signs and units are ignored. A lone separator is the
 * decimal point unless it is the locale's grouping separator followed by
 * groups of three digits; with both, the last one is the decimal point.
 */
final class PrintedNumber
{
    public static function read(string $printed, string $locale): ?string
    {
        // Spaces and apostrophes group thousands (48 120, 1'234.50).
        $text = (string) preg_replace("/(?<=\\d)[\\s\\x{00A0}\\x{202F}'’](?=\\d{3}\\b)/u", '', $printed);
        if (preg_match('/-?\d[\d.,]*/', $text, $m) !== 1) {
            return null;
        }
        $number = rtrim($m[0], '.,');
        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '-');

        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $decimal = self::isGrouping($number, $separator, $locale) ? null : $separator;
        } else {
            $decimal = null;
        }

        $grouping = $decimal === ',' ? '.' : ',';
        $clean = str_replace($decimal === null ? [',', '.'] : [$grouping], '', $number);
        if ($decimal === ',') {
            $clean = str_replace(',', '.', $clean);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $clean) !== 1) {
            return null;
        }
        $clean = ltrim($clean, '0');
        $clean = $clean === '' || str_starts_with($clean, '.') ? '0' . $clean : $clean;

        return ($negative ? '-' : '') . $clean;
    }

    private static function isGrouping(string $number, string $separator, string $locale): bool
    {
        $groups = preg_match('/^\d{1,3}(' . preg_quote($separator, '/') . '\d{3})+$/', $number) === 1;
        if (!$groups) {
            return false;
        }
        if (substr_count($number, $separator) > 1) {
            return true;
        }
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);

        return $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL) === $separator;
    }
}
