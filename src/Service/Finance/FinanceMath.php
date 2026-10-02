<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;

/**
 * The arithmetic behind an agreement's figures (spec.md §7.32), in exact
 * decimals (`brick/math`): never floats for money.
 */
final class FinanceMath
{
    /** Places the monthly rate is kept to (spec.md §7.32 *Figures*). */
    public const int RATE_SCALE = 10;
    /** Working precision for powers and roots. */
    private const int WORK_SCALE = 30;

    /**
     * The monthly rate from an APR: (1 + APR)^(1/12) − 1, to 10 places.
     * brick/math raises only to whole powers, so the twelfth root is found
     * by Newton's method from a float first guess; the guess only seeds
     * the iteration, which converges to the exact digits.
     *
     * @param string $apr percent, e.g. "9.9"
     */
    public static function monthlyRate(string $apr): BigDecimal
    {
        $annual = BigDecimal::of($apr)->dividedBy(100, self::WORK_SCALE, RoundingMode::HalfUp);
        if ($annual->isZero()) {
            return BigDecimal::zero()->toScale(self::RATE_SCALE);
        }
        $base = BigDecimal::one()->plus($annual);
        $root = BigDecimal::of(sprintf('%.15F', ((float) (string) $base) ** (1 / 12)));
        for ($i = 0; $i < 8; $i++) {
            // x ← (11x + a / x^11) / 12
            $next = $root->multipliedBy(11)
                ->plus($base->dividedBy($root->power(11), self::WORK_SCALE, RoundingMode::HalfUp))
                ->dividedBy(12, self::WORK_SCALE, RoundingMode::HalfUp);
            if ($next->isEqualTo($root)) {
                break;
            }
            $root = $next;
        }

        return $root->minus(1)->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
    }

    /**
     * (1 + rate)^months, at working precision.
     */
    public static function growth(BigDecimal $rate, int $months): BigDecimal
    {
        return BigDecimal::one()->plus($rate)->power(max(0, $months))->toScale(self::WORK_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Whole calendar months from one date to a later one (by month, not
     * day), at least 1 when $to is after $from: the periods between
     * consecutive payments.
     */
    public static function monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + (int) $to->format('n') - (int) $from->format('n');

        return max(1, $months);
    }

    /**
     * The months a payment on $due is discounted by at $today: the fewest
     * whole months after which $today has reached it (a payment due tomorrow
     * or in exactly a month is one month away; one due today or overdue,
     * none).
     */
    public static function monthsUntil(DateTimeImmutable $today, DateTimeImmutable $due): int
    {
        if ($due <= $today) {
            return 0;
        }
        $months = self::monthsBetween($today, $due);
        while (LocalTime::addMonths($today, $months) < $due) {
            $months++;
        }
        while ($months > 1 && LocalTime::addMonths($today, $months - 1) >= $due) {
            $months--;
        }

        return $months;
    }

    /** An amount rounded to pennies (half up), as a canonical decimal. */
    public static function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }

    public static function of(?string $amount): BigDecimal
    {
        return BigDecimal::of($amount ?? '0');
    }
}
