<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use UnexpectedValueException;

/**
 * Typed reads from a fetched row. Drivers disagree on scalar types (pdo_pgsql
 * returns ints as ints, pdo_mysql may return them as strings), so repositories
 * normalise through here instead of casting blindly.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = self::value($row, $column);
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException(sprintf('Column "%s" is not a string.', $column));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = self::value($row, $column);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('Column "%s" is not an integer.', $column));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function value(array $row, string $column): mixed
    {
        // Some engines return upper-case column names for unquoted identifiers.
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }
        foreach ($row as $key => $value) {
            if (strcasecmp($key, $column) === 0) {
                return $value;
            }
        }

        throw new UnexpectedValueException(sprintf('Column "%s" missing from result row.', $column));
    }
}
