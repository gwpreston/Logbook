<?php

declare(strict_types=1);

namespace Logbook\Support\Number;

use NumberFormatter;

/**
 * Turns user input into a canonical decimal string ("1234.5"), without floats.
 *
 * Forms use `<input type="number">`, which browsers always submit in the
 * canonical form, so that is tried first. As a fallback the user's locale
 * format is accepted ("1.234,5" in German, "1 234,5" in French); grouping
 * separators must sit in the right places, so "1,5" is rejected in English
 * rather than silently read as 15.
 */
final class DecimalParser
{
    /** Spaces people (and locales) use as thousands separators. */
    private const array SPACES = [' ', "\u{00A0}", "\u{202F}", "\u{2009}"];

    public static function parse(string $input, string $locale): ?string
    {
        $value = trim($input);
        if ($value === '') {
            return null;
        }

        $canonical = self::canonical($value);
        if ($canonical !== null) {
            return $canonical;
        }

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $decimalSymbol = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) ?: '.';
        $groupingSymbol = $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);

        $value = self::normaliseMinus($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-+');

        $parts = explode($decimalSymbol, $value);
        if (count($parts) > 2) {
            return null;
        }
        [$whole, $fraction] = array_pad($parts, 2, null);

        $groupingSymbols = in_array($groupingSymbol, self::SPACES, true)
            ? self::SPACES
            : [$groupingSymbol, ...self::SPACES];
        $whole = self::ungroup($whole ?? '', $groupingSymbols);
        if ($whole === null) {
            return null;
        }

        return self::canonical(($negative ? '-' : '') . $whole . ($fraction === null ? '' : '.' . $fraction));
    }

    /**
     * Accept "1234.5", "+1234.5", ".5", "5." and "007" as canonical input.
     */
    private static function canonical(string $value): ?string
    {
        $value = self::normaliseMinus($value);
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $whole = ltrim($m[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $m[3] ?? '';
        $result = $whole . ($fraction === '' ? '' : '.' . $fraction);

        return $m[1] === '-' && trim($result, '0.') !== '' ? '-' . $result : $result;
    }

    /**
     * Remove grouping separators, insisting on groups of three digits
     * (two are allowed before the last group, for Indian-style grouping).
     *
     * @param list<string> $symbols
     */
    private static function ungroup(string $whole, array $symbols): ?string
    {
        $pattern = '/(?:' . implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $symbols)) . ')/u';
        $groups = preg_split($pattern, $whole);
        if ($groups === false) {
            return null;
        }

        $count = count($groups);
        foreach ($groups as $index => $group) {
            if (preg_match('/^\d+$/', $group) !== 1) {
                return null;
            }
            if ($index === 0 || $count === 1) {
                continue;
            }
            $length = strlen($group);
            if ($length !== 3 && !($length === 2 && $index < $count - 1)) {
                return null;
            }
        }

        return implode('', $groups);
    }

    private static function normaliseMinus(string $value): string
    {
        return str_replace(["\u{2212}", "\u{2012}", "\u{2013}"], '-', $value);
    }
}
