<?php

declare(strict_types=1);

namespace Logbook\Support\Number;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact arithmetic helpers for canonical decimal strings ("-1234.5").
 *
 * Money and stored quantities never pass through floats on their way to the
 * database: they are carried as canonical strings, or as integers scaled by a
 * power of ten, and rounded half away from zero.
 */
final class Decimal
{
    private const string CANONICAL = '/^-?\d+(?:\.\d+)?$/';

    public static function isCanonical(string $value): bool
    {
        return preg_match(self::CANONICAL, $value) === 1;
    }

    /**
     * "12.3456" at scale 2 → 1235 (half away from zero).
     *
     * @throws InvalidArgumentException when $value is not canonical
     * @throws OverflowException when the result does not fit in a PHP int
     */
    public static function toScaledInt(string $value, int $scale): int
    {
        if (!self::isCanonical($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a canonical decimal.', $value));
        }

        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $whole = ltrim($whole, '0');

        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $roundUp = strlen($fraction) > $scale && (int) $fraction[$scale] >= 5;

        $digits = ltrim($whole . $kept, '0');
        if (strlen($digits) > 18) {
            throw new OverflowException(sprintf('"%s" is too large.', $value));
        }

        $magnitude = (int) ($digits === '' ? '0' : $digits) + ($roundUp ? 1 : 0);

        return $negative ? -$magnitude : $magnitude;
    }

    /**
     * 1235 at scale 2 → "12.35".
     */
    public static function fromScaledInt(int $value, int $scale): string
    {
        $digits = str_pad((string) abs($value), $scale + 1, '0', STR_PAD_LEFT);
        $sign = $value < 0 ? '-' : '';

        if ($scale === 0) {
            return $sign . $digits;
        }

        return $sign . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    /**
     * Round a canonical decimal to exactly $scale places: "1.2345" → "1.235".
     */
    public static function round(string $value, int $scale): string
    {
        return self::fromScaledInt(self::toScaledInt($value, $scale), $scale);
    }

    /**
     * Canonical string for a float. Only for values that already went
     * through floats (unit conversions, SQLite REAL columns).
     */
    public static function fromFloat(float $value, int $scale): string
    {
        $formatted = number_format($value, $scale, '.', '');

        return $formatted === '-' . number_format(0, $scale, '.', '') ? substr($formatted, 1) : $formatted;
    }

    public static function compare(string $a, string $b): int
    {
        $scale = max(self::scaleOf($a), self::scaleOf($b));

        return self::toScaledInt($a, $scale) <=> self::toScaledInt($b, $scale);
    }

    public static function add(string $a, string $b): string
    {
        $scale = max(self::scaleOf($a), self::scaleOf($b));

        return self::fromScaledInt(self::toScaledInt($a, $scale) + self::toScaledInt($b, $scale), $scale);
    }

    public static function subtract(string $a, string $b): string
    {
        $scale = max(self::scaleOf($a), self::scaleOf($b));

        return self::fromScaledInt(self::toScaledInt($a, $scale) - self::toScaledInt($b, $scale), $scale);
    }

    /**
     * $a × $b rounded to $scale places (half away from zero), exactly.
     *
     * Only if the intermediate product would not fit in a PHP int (far beyond
     * any real fuel quantity or price) does it fall back to float arithmetic.
     */
    public static function multiply(string $a, string $b, int $scale): string
    {
        $scaleA = self::scaleOf($a);
        $scaleB = self::scaleOf($b);
        $intA = self::toScaledInt($a, $scaleA);
        $intB = self::toScaledInt($b, $scaleB);

        if ($intB !== 0 && abs($intA) > intdiv(PHP_INT_MAX, abs($intB))) {
            return self::fromFloat((float) $a * (float) $b, $scale);
        }

        return self::rescale($intA * $intB, $scaleA + $scaleB, $scale) ?? self::fromFloat((float) $a * (float) $b, $scale);
    }

    /**
     * $a ÷ $b rounded to $scale places (half away from zero), exactly (with
     * the same float fallback as multiply()).
     *
     * @throws InvalidArgumentException when $b is zero
     */
    public static function divide(string $a, string $b, int $scale): string
    {
        $scaleA = self::scaleOf($a);
        $scaleB = self::scaleOf($b);
        $numerator = self::toScaledInt($a, $scaleA);
        $denominator = self::toScaledInt($b, $scaleB);
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        // a/b = (A / 10^sa) / (B / 10^sb); the result scaled by 10^scale is
        // A × 10^(scale + sb - sa) / B.
        $shift = $scale + $scaleB - $scaleA;
        $numerator = $shift >= 0 ? self::timesPowerOfTen($numerator, $shift) : $numerator;
        $denominator = $shift < 0 ? self::timesPowerOfTen($denominator, -$shift) : $denominator;
        if ($numerator === null || $denominator === null) {
            return self::fromFloat((float) $a / (float) $b, $scale);
        }

        return self::fromScaledInt(self::roundedDivision($numerator, $denominator), $scale);
    }

    /**
     * Drop insignificant zeros: "12.500" → "12.5", "3.000" → "3".
     */
    public static function trim(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '-0' ? '0' : $trimmed;
    }

    private static function scaleOf(string $value): int
    {
        $dot = strpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    /**
     * An integer at scale $from re-expressed at scale $to (rounded), or null
     * on overflow.
     */
    private static function rescale(int $value, int $from, int $to): ?string
    {
        if ($to >= $from) {
            $scaled = self::timesPowerOfTen($value, $to - $from);

            return $scaled === null ? null : self::fromScaledInt($scaled, $to);
        }

        return self::fromScaledInt(self::roundedDivision($value, 10 ** ($from - $to)), $to);
    }

    private static function timesPowerOfTen(int $value, int $exponent): ?int
    {
        if ($exponent > 18) {
            return $value === 0 ? 0 : null;
        }
        $factor = 10 ** $exponent;

        return abs($value) > intdiv(PHP_INT_MAX, $factor) ? null : $value * $factor;
    }

    /**
     * $numerator / $denominator rounded half away from zero.
     */
    private static function roundedDivision(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = abs($numerator % $denominator);

        // 2r >= |d|, written so it cannot overflow.
        if ($remainder >= abs($denominator) - $remainder) {
            $quotient += ($numerator < 0) === ($denominator < 0) ? 1 : -1;
        }

        return $quotient;
    }
}
