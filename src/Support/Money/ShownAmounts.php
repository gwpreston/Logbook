<?php

declare(strict_types=1);

namespace Logbook\Support\Money;

use Brick\Math\BigRational;
use InvalidArgumentException;
use Logbook\Support\Number\Apportion;

/**
 * Amounts as they are shown beside the total they make up (spec.md §8
 * *Shown parts add up*, #403): the total is rounded once, half away from
 * zero, to the currency's minor unit (or to $places), and its parts are
 * rounded to the same unit so that they add up to it exactly. The exact
 * sums underneath are never changed; only what is shown is.
 *
 * - A list of parts is rounded by the largest-remainder method.
 * - A grid (Reports' months × groups) is rounded under control (#403,
 *   decided 2026-10-12): every cell goes up or down a unit at most, each
 *   row adds up to its shown total, each column to its shown total, and
 *   those to the grand total. A cell that is exactly a whole unit (zero
 *   included) is never moved.
 */
final class ShownAmounts
{
    /**
     * The total and its parts, rounded so the parts add up to the total.
     *
     * @template K of array-key
     * @param array<K, Money> $parts in the total's currency; they need not add up to it exactly
     * @return array{0: Money, 1: array<K, Money>} the shown total, and the shown parts with their keys
     */
    public static function parts(Money $total, array $parts, ?int $places = null): array
    {
        $places ??= Currency::fractionDigits($total->currency);
        $shown = self::round($total, $places);
        if ($parts === []) {
            return [$shown, []];
        }
        $keys = array_keys($parts);
        $rounded = Apportion::toTarget(
            array_values(array_map(static fn (Money $m): BigRational => BigRational::of($m->toDecimal(Money::SCALE)), $parts)),
            $shown->toDecimal($places),
            max(0, $places),
        );

        $money = array_map(static fn (string $d): Money => Money::of($d, $total->currency), $rounded);

        return [$shown, array_combine($keys, $money)];
    }

    /**
     * $amount rounded once, half away from zero, to $places (default: the
     * currency's minor unit).
     */
    public static function round(Money $amount, ?int $places = null): Money
    {
        $places ??= Currency::fractionDigits($amount->currency);
        $unit = self::unit($places);
        $units = intdiv(abs($amount->micros) + intdiv($unit, 2), $unit) * $unit;

        return Money::of(self::micros($amount->micros < 0 ? -$units : $units), $amount->currency);
    }

    /**
     * A grid rounded under control: each cell to a neighbouring unit, rows
     * and columns adding up to their shown totals, and those to the shown
     * grand total (the grand total rounded once). The columns' totals are
     * $columnTargets when given (each must be a neighbouring unit of its
     * column's exact sum, and they must add up to the grand total) and when
     * a grid can meet them; otherwise each is a neighbouring unit of its
     * exact sum.
     *
     * @template R of array-key
     * @template C of array-key
     * @param array<R, array<C, Money>> $cells every row with the same columns, one currency
     * @param array<C, Money>|null $columnTargets
     * @return array{cells: array<R, array<C, Money>>, rows: array<R, Money>, columns: array<C, Money>, total: Money}
     */
    public static function grid(array $cells, string $currency, ?array $columnTargets = null): array
    {
        $unit = self::unit(Currency::fractionDigits($currency));
        $rowKeys = array_keys($cells);
        $colKeys = $rowKeys === [] ? [] : array_keys($cells[$rowKeys[0]]);
        $grand = 0;
        /** @var array<int, array<int, int>> $floor */
        $floor = [];
        /** @var array<int, array<int, bool>> $loose */
        $loose = [];
        $rowRem = array_fill(0, count($rowKeys), 0);
        $colRem = array_fill(0, count($colKeys), 0);
        $floorSum = 0;
        foreach ($rowKeys as $i => $r) {
            foreach ($colKeys as $j => $c) {
                $micros = $cells[$r][$c]->micros;
                $grand += $micros;
                $f = intdiv($micros, $unit) - ($micros % $unit < 0 ? 1 : 0);
                $rem = $micros - $f * $unit;
                $floor[$i][$j] = $f;
                $loose[$i][$j] = $rem > 0;
                $rowRem[$i] += $rem;
                $colRem[$j] += $rem;
                $floorSum += $f;
            }
        }
        $total = self::round(Money::of(self::micros($grand), $currency));
        $extra = intdiv($total->micros, $unit) - $floorSum;

        $bounds = static fn (int $rem): array => [intdiv($rem, $unit), intdiv($rem, $unit) + ($rem % $unit > 0 ? 1 : 0)];
        $rows = array_values(array_map($bounds, $rowRem));
        $free = array_values(array_map($bounds, $colRem));
        $add = null;
        if ($columnTargets !== null) {
            $fixed = [];
            foreach ($colKeys as $j => $c) {
                $sum = 0;
                foreach (array_keys($rowKeys) as $i) {
                    $sum += $floor[$i][$j];
                }
                $k = intdiv($columnTargets[$c]->micros, $unit) - $sum;
                $fixed[] = [$k, $k];
            }
            $add = self::flow($loose, $rows, $fixed, $extra);
        }
        $add ??= self::flow($loose, $rows, $free, $extra)
            ?? throw new InvalidArgumentException('No controlled rounding: the grid is inconsistent.');

        $out = [];
        $rowTotals = [];
        $colTotals = array_fill(0, count($colKeys), 0);
        foreach ($rowKeys as $i => $r) {
            $rowTotal = 0;
            foreach ($colKeys as $j => $c) {
                $units = $floor[$i][$j] + ($add[$i][$j] ?? 0);
                $out[$r][$c] = Money::of(self::micros($units * $unit), $currency);
                $rowTotal += $units;
                $colTotals[$j] += $units;
            }
            $rowTotals[$r] = Money::of(self::micros($rowTotal * $unit), $currency);
        }
        $columns = [];
        foreach ($colKeys as $j => $c) {
            $columns[$c] = Money::of(self::micros($colTotals[$j] * $unit), $currency);
        }

        return ['cells' => $out, 'rows' => $rowTotals, 'columns' => $columns, 'total' => $total];
    }

    /**
     * Which loose cells go up a unit: a 0/1 flow from rows to columns with
     * each row's and column's count between its bounds and $extra in all
     * (a feasible flow with lower bounds, by max-flow). Null when none.
     *
     * @param array<int, array<int, bool>> $loose
     * @param list<array{int, int}> $rows
     * @param list<array{int, int}> $columns
     * @return array<int, array<int, int>>|null
     */
    private static function flow(array $loose, array $rows, array $columns, int $extra): ?array
    {
        $m = count($rows);
        $n = count($columns);
        // Nodes: 0 source, 1..m rows, m+1..m+n columns, m+n+1 sink, then the super source and sink.
        $s = 0;
        $t = $m + $n + 1;
        $ss = $t + 1;
        $tt = $t + 2;
        // Edge e goes to $head[e] with $room[e] left; its reverse is e ^ 1.
        /** @var list<int> $head */
        $head = [];
        /** @var list<int> $room */
        $room = [];
        /** @var array<int, list<int>> $out */
        $out = array_fill(0, $tt + 1, []);
        $need = array_fill(0, $tt + 1, 0);
        $add = static function (int $from, int $to, int $capacity) use (&$head, &$room, &$out): int {
            $e = count($head);
            $out[$from][] = $e;
            $head[] = $to;
            $room[] = $capacity;
            $out[$to][] = $e + 1;
            $head[] = $from;
            $room[] = 0;

            return $e;
        };
        $bounded = static function (int $from, int $to, int $low, int $high) use ($add, &$need): int {
            $need[$to] += $low;
            $need[$from] -= $low;

            return $add($from, $to, $high - $low);
        };
        foreach ($rows as $i => [$low, $high]) {
            $bounded($s, 1 + $i, $low, $high);
        }
        foreach ($columns as $j => [$low, $high]) {
            $bounded($m + 1 + $j, $t, $low, $high);
        }
        $cells = [];
        foreach ($loose as $i => $row) {
            foreach ($row as $j => $isLoose) {
                if ($isLoose) {
                    $cells[$i][$j] = $add(1 + $i, $m + 1 + $j, 1);
                }
            }
        }
        $bounded($t, $s, $extra, $extra);
        $wanted = 0;
        foreach ($need as $node => $amount) {
            if ($amount > 0) {
                $add($ss, $node, $amount);
                $wanted += $amount;
            } elseif ($amount < 0) {
                $add($node, $tt, -$amount);
            }
        }

        $flowed = 0;
        while (true) {
            $via = array_fill(0, $tt + 1, -1);
            $via[$ss] = -2;
            $queue = [$ss];
            for ($k = 0; $k < count($queue) && $via[$tt] === -1; $k++) {
                foreach ($out[$queue[$k]] as $e) {
                    if ($room[$e] > 0 && $via[$head[$e]] === -1) {
                        $via[$head[$e]] = $e;
                        $queue[] = $head[$e];
                    }
                }
            }
            if ($via[$tt] === -1) {
                break;
            }
            $push = PHP_INT_MAX;
            for ($v = $tt; $v !== $ss; $v = $head[$via[$v] ^ 1]) {
                $push = min($push, $room[$via[$v]]);
            }
            for ($v = $tt; $v !== $ss; $v = $head[$via[$v] ^ 1]) {
                $room[$via[$v]] -= $push;
                $room[$via[$v] ^ 1] += $push;
            }
            $flowed += $push;
        }
        if ($flowed !== $wanted) {
            return null;
        }

        // A cell went up when its edge's one unit was used.
        return array_map(static fn (array $row): array => array_map(static fn (int $e): int => 1 - $room[$e], $row), $cells);
    }

    private static function unit(int $places): int
    {
        if ($places < 0 || $places > Money::SCALE) {
            throw new InvalidArgumentException("Cannot show money to {$places} places");
        }

        return 10 ** (Money::SCALE - $places);
    }

    private static function micros(int $micros): string
    {
        $sign = $micros < 0 ? '-' : '';
        $abs = abs($micros);

        $scale = 10 ** Money::SCALE;

        return $sign . intdiv($abs, $scale) . '.' . str_pad((string) ($abs % $scale), Money::SCALE, '0', STR_PAD_LEFT);
    }
}
