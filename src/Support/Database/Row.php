<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
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
     * A boolean column: PostgreSQL returns a bool, MySQL and SQLite 0/1 as
     * ints or strings.
     *
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $column): bool
    {
        $value = self::value($row, $column);

        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 't', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'f', $value === 'false' => false,
            default => throw new UnexpectedValueException(sprintf('Column "%s" is not a boolean.', $column)),
        };
    }

    /**
     * A DECIMAL column that is never null, as a canonical string at $scale.
     *
     * @param array<string, mixed> $row
     */
    public static function decimal(array $row, string $column, int $scale): string
    {
        return self::nullableDecimal($row, $column, $scale)
            ?? throw new UnexpectedValueException(sprintf('Column "%s" is null.', $column));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        return self::value($row, $column) === null ? null : self::string($row, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        return self::value($row, $column) === null ? null : self::int($row, $column);
    }

    /**
     * A DECIMAL column as a canonical string at the column's scale. PostgreSQL
     * and MySQL return strings; SQLite may return ints or floats.
     *
     * @param array<string, mixed> $row
     */
    public static function nullableDecimal(array $row, string $column, int $scale): ?string
    {
        $value = self::value($row, $column);

        return match (true) {
            $value === null => null,
            is_int($value), is_float($value) => Decimal::fromFloat((float) $value, $scale),
            is_string($value) && Decimal::isCanonical($value) => Decimal::round($value, $scale),
            default => throw new UnexpectedValueException(sprintf('Column "%s" is not a decimal.', $column)),
        };
    }

    /**
     * A DATE column as a calendar date (midnight UTC; see Support\Date\LocalTime).
     *
     * @param array<string, mixed> $row
     */
    public static function nullableDate(array $row, string $column): ?DateTimeImmutable
    {
        $value = self::value($row, $column);
        if ($value === null) {
            return null;
        }

        $date = is_string($value) ? LocalTime::parseDate(substr($value, 0, 10)) : null;
        if ($date === null) {
            throw new UnexpectedValueException(sprintf('Column "%s" is not a date.', $column));
        }

        return $date;
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
