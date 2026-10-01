<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Support\Number\Decimal;

/**
 * Arithmetic shared by the price and cost outlier checks (spec.md §7.24
 * items 8 and 9): an exact median, and whether a ratio looks like a digit
 * typed once too often or left out. Pure.
 */
final class Outlier
{
    public const int SCALE = 6;

    /** A ratio within ×/÷ this of 10, 100 or 1,000 (or their inverses) reads as a digit slip. */
    public const string DIGIT_TOLERANCE = '1.25';

    /**
     * The median, one place more than the inputs' scale so the mean of two
     * middle values is exact.
     *
     * @param non-empty-list<string> $values
     */
    public static function median(array $values): string
    {
        usort($values, Decimal::compare(...));
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? Decimal::round($values[$middle], self::SCALE + 1)
            : Decimal::divide(Decimal::add($values[$middle - 1], $values[$middle]), '2', self::SCALE + 1);
    }

    /**
     * $value ÷ $median, 6 places; $median must be above 0.
     */
    public static function ratio(string $value, string $median): string
    {
        return Decimal::divide($value, $median, self::SCALE);
    }

    /**
     * Whether the ratio is within DIGIT_TOLERANCE of 10, 100 or 1,000, or
     * of 0.1, 0.01 or 0.001: "an extra or missing digit?".
     */
    public static function isDigitSlip(string $ratio): bool
    {
        foreach (['10', '100', '1000'] as $power) {
            $high = Decimal::multiply($power, self::DIGIT_TOLERANCE, self::SCALE);
            $low = Decimal::divide($power, self::DIGIT_TOLERANCE, self::SCALE);
            if (Decimal::compare($ratio, $low) >= 0 && Decimal::compare($ratio, $high) <= 0) {
                return true;
            }
            // The inverse: ratio × power within the same band of 1.
            $inverse = Decimal::multiply($ratio, $power, self::SCALE);
            if (
                Decimal::compare($inverse, Decimal::divide('1', self::DIGIT_TOLERANCE, self::SCALE)) >= 0
                && Decimal::compare($inverse, self::DIGIT_TOLERANCE) <= 0
            ) {
                return true;
            }
        }

        return false;
    }
}
