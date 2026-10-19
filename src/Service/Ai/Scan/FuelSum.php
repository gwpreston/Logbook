<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Brick\Math\BigDecimal;

/**
 * Whether a fuel receipt's volume × price per unit makes its total
 * (spec.md §7.27 *The money adds up*, Phase 53.3): within 1p, plus what
 * each figure's printed rounding allows (half a unit in its last printed
 * decimal, times the other). In the printed units, before any conversion.
 * The Mapper marks figures that don't add up; reading without AI (Phase
 * 53.4) fills only figures that do, by the same rule.
 */
final class FuelSum
{
    /**
     * @param string $volume a read number (PrintedNumber::read)
     * @param string $price a read number; in pence (or cents) when $pence
     * @param string $total a read number
     */
    public static function agrees(string $volume, string $price, string $total, bool $pence): bool
    {
        $v = BigDecimal::of($volume);
        $p = BigDecimal::of($price);
        if ($pence) {
            $p = $p->dividedBy(100, $p->getScale() + 2);
        }
        $allowed = BigDecimal::of('0.01')
            ->plus($p->abs()->multipliedBy(self::halfUnit($v)))
            ->plus($v->abs()->multipliedBy(self::halfUnit($p)));

        return $v->multipliedBy($p)->minus(BigDecimal::of($total))->abs()->isLessThanOrEqualTo($allowed);
    }

    /**
     * Volume × price, the price in pounds (or euros), unrounded.
     */
    public static function product(string $volume, string $price, bool $pence): BigDecimal
    {
        $p = BigDecimal::of($price);

        return BigDecimal::of($volume)->multipliedBy($pence ? $p->dividedBy(100, $p->getScale() + 2) : $p);
    }

    private static function halfUnit(BigDecimal $number): BigDecimal
    {
        return BigDecimal::ofUnscaledValue(5, $number->getScale() + 1);
    }
}
