<?php

declare(strict_types=1);

namespace Logbook\Support\Number;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Rounds a set of exact parts so they add up exactly to a rounded whole
 * (largest remainder): each part is floored, and the units the floors fall
 * short are handed to the parts with the largest remainders. Used wherever a
 * figure is shown beside the parts it is made of (spec.md §7.35), so the
 * parts never stop adding up.
 */
final class Apportion
{
    /**
     * The parts at $scale places, adding up to their exact sum rounded half
     * up to $scale places.
     *
     * @param list<BigRational> $parts
     * @param int<0, max> $scale
     * @return list<string> canonical decimals, in the order given
     */
    public static function toScale(array $parts, int $scale): array
    {
        $sum = BigRational::zero();
        foreach ($parts as $part) {
            $sum = $sum->plus($part);
        }

        return self::toTarget($parts, $sum->toScale($scale, RoundingMode::HalfUp), $scale);
    }

    /**
     * Each part's share of the parts above zero as whole percentages adding
     * up to exactly 100 (spec.md §7.8 *Expense breakdown*); all zeros when
     * nothing is above zero. A part below zero (a finance end adjustment,
     * §7.7, #387) has no share: null. Parts are integers (money in micros).
     *
     * @param list<int> $parts
     * @return list<int|null> in the order given
     */
    public static function percentages(array $parts): array
    {
        $sum = array_sum(array_filter($parts, static fn (int $part): bool => $part > 0));
        $positive = array_values(array_filter($parts, static fn (int $part): bool => $part >= 0));
        $whole = $sum <= 0
            ? array_fill(0, count($positive), 0)
            : array_map('intval', self::toTarget(array_map(
                static fn (int $part): BigRational => BigRational::ofFraction(BigInteger::of($part)->multipliedBy(100), $sum),
                $positive,
            ), '100', 0));

        $out = [];
        foreach ($parts as $part) {
            $out[] = $part < 0 ? null : array_shift($whole);
        }

        return $out;
    }

    /**
     * Each part's share of the parts above zero, in percent (for bar widths
     * and the Reports breakdown, spec.md §7.7); null for a part below zero,
     * which is listed with no share (#387).
     *
     * @param list<int> $parts integers (money in micros)
     * @return list<float|null> in the order given
     */
    public static function shares(array $parts): array
    {
        $sum = array_sum(array_filter($parts, static fn (int $part): bool => $part > 0));

        return array_map(
            static fn (int $part): ?float => $part < 0 ? null : ($sum === 0 ? 0.0 : $part / $sum * 100),
            $parts,
        );
    }

    /**
     * The parts at $scale places, adding up to $target exactly. The target
     * may differ from the parts' exact sum by a few units (it was rounded
     * elsewhere): the difference goes to the parts with the largest
     * remainders first (the smallest, when taking units away).
     *
     * @param list<BigRational> $parts
     * @param int<0, max> $scale
     * @return list<string> canonical decimals, in the order given
     */
    public static function toTarget(array $parts, BigDecimal|string $target, int $scale): array
    {
        if ($parts === []) {
            return [];
        }
        $unit = BigRational::ofFraction(1, BigInteger::ten()->power($scale));
        $floors = [];
        $remainders = [];
        $sum = BigInteger::zero();
        foreach ($parts as $i => $part) {
            $units = $part->dividedBy($unit);
            $floor = $units->toScale(0, RoundingMode::Floor)->toBigInteger();
            $floors[$i] = $floor;
            $remainders[$i] = $units->minus($floor);
            $sum = $sum->plus($floor);
        }
        $wanted = BigDecimal::of($target)->toScale($scale, RoundingMode::HalfUp)->getUnscaledValue();
        $short = $wanted->minus($sum)->toInt();

        // Largest remainder first; ties to the earlier part.
        $order = array_keys($parts);
        usort($order, static fn (int $a, int $b): int => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);
        $count = count($order);
        for ($k = 0; $short > 0; $k++, $short--) {
            $i = $order[$k % $count];
            $floors[$i] = $floors[$i]->plus(1);
        }
        for ($k = 0; $short < 0; $k++, $short++) {
            $i = $order[$count - 1 - ($k % $count)];
            $floors[$i] = $floors[$i]->minus(1);
        }

        return array_values(array_map(
            static fn (BigInteger $units): string => BigDecimal::ofUnscaledValue($units, $scale)->toString(),
            $floors,
        ));
    }
}
