<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

use Logbook\Support\Number\Decimal;

/**
 * Writes CSV (RFC 4180): comma-separated, CRLF line ends, fields quoted when
 * they contain a comma, quote or line break. UTF-8 with a byte-order mark so
 * spreadsheet programs pick the right encoding (spec.md §7.7).
 *
 * Text that a spreadsheet would run as a formula (starting with =, +, -, @,
 * a tab or a carriage return) is prefixed with an apostrophe. Numbers are
 * written as canonical decimals and left alone, so "-1.5" stays a number.
 */
final class CsvWriter
{
    public const string BOM = "\u{FEFF}";

    /**
     * @param list<string> $header
     * @param iterable<list<string|null>> $rows
     */
    public static function document(array $header, iterable $rows): string
    {
        $lines = [self::line($header)];
        foreach ($rows as $row) {
            $lines[] = self::line($row);
        }

        return self::BOM . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string|null> $cells
     */
    public static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells));
    }

    public static function cell(?string $value): string
    {
        $value ??= '';
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false && !Decimal::isCanonical($value)) {
            $value = "'" . $value;
        }

        if (strpbrk($value, ",\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
