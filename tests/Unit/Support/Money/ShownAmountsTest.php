<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Money;

use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;
use Logbook\Tests\Support\Golden\Rng;
use PHPUnit\Framework\TestCase;

/**
 * Shown parts add up (spec.md §8, #403): a list by largest remainder, a grid
 * under controlled rounding.
 */
final class ShownAmountsTest extends TestCase
{
    public function testThreePartsOfAPennyAndAHalfAddUpToTheirTotal(): void
    {
        // Each £1.005 shows as £1.01 on its own: £3.03 against a £3.02 total.
        $part = Money::of('1.005', 'GBP');
        [$total, $parts] = ShownAmounts::parts(Money::of('3.015', 'GBP'), ['a' => $part, 'b' => $part, 'c' => $part]);

        self::assertSame('3.02', $total->toDecimal(2));
        self::assertSame(['a' => '1.01', 'b' => '1.01', 'c' => '1.00'], self::pence($parts));
    }

    public function testATotalIsRoundedOnceHalfAwayFromZero(): void
    {
        self::assertSame('1.01', ShownAmounts::round(Money::of('1.005', 'GBP'))->toDecimal(2));
        self::assertSame('-1.01', ShownAmounts::round(Money::of('-1.005', 'GBP'))->toDecimal(2));
        self::assertSame('121', ShownAmounts::round(Money::of('120.5', 'JPY'))->toDecimal(0));
        self::assertSame('1.235', ShownAmounts::round(Money::of('1.2345', 'BHD'))->toDecimal(3));
        self::assertSame('1.001', ShownAmounts::round(Money::of('1.0005', 'GBP'), 3)->toDecimal(3), 'a CSV keeps 3 places');
    }

    public function testNegativePartsKeepTheirSignAndStillAddUp(): void
    {
        // £60.004 of fuel, a −£25.003 finance adjustment and £0.004 of parking.
        $parts = [Money::of('60.004', 'GBP'), Money::of('-25.003', 'GBP'), Money::of('0.004', 'GBP')];
        [$total, $shown] = ShownAmounts::parts(Money::of('35.005', 'GBP'), $parts);

        self::assertSame('35.01', $total->toDecimal(2));
        self::assertSame(['60.01', '-25.00', '0.00'], self::pence($shown));
    }

    public function testWholeUnitsAreNeverMoved(): void
    {
        [$total, $parts] = ShownAmounts::parts(Money::of('12', 'GBP'), [Money::of('5', 'GBP'), Money::of('7', 'GBP')]);

        self::assertSame('12.00', $total->toDecimal(2));
        self::assertSame(['5.00', '7.00'], self::pence($parts));
    }

    public function testAGridAddsUpEveryWayAndMeetsTheColumnTargets(): void
    {
        $grid = [
            'Jan' => ['fuel' => Money::of('1.005', 'GBP'), 'other' => Money::of('2.005', 'GBP')],
            'Feb' => ['fuel' => Money::of('1.005', 'GBP'), 'other' => Money::of('0', 'GBP')],
            'Mar' => ['fuel' => Money::of('1.005', 'GBP'), 'other' => Money::of('-0.505', 'GBP')],
        ];
        $targets = ['fuel' => Money::of('3.02', 'GBP'), 'other' => Money::of('1.50', 'GBP')];

        $shown = ShownAmounts::grid($grid, 'GBP', $targets);

        self::assertSame('4.52', $shown['total']->toDecimal(2), '4.52 exactly: 3.015 + 1.5');
        self::assertSame(['fuel' => '3.02', 'other' => '1.50'], self::pence($shown['columns']));
        self::assertSame('0.00', $shown['cells']['Feb']['other']->toDecimal(2), 'a zero stays zero');
        self::assertGridAddsUp($grid, $shown);
    }

    /**
     * Seeded random grids: every cell within a unit, rows and columns adding
     * up, the grand total rounded once, zeros never moved.
     */
    public function testRandomGridsAlwaysRoundUnderControl(): void
    {
        $rng = new Rng(403);
        for ($case = 0; $case < 200; $case++) {
            $rows = $rng->between(1, 13);
            $cols = $rng->between(1, 5);
            $grid = [];
            for ($i = 0; $i < $rows; $i++) {
                for ($j = 0; $j < $cols; $j++) {
                    $micros = $rng->oneIn(4) ? 0 : $rng->between(-2_000, 400_000) * 1_000 + ($rng->oneIn(2) ? 5_000 : 0);
                    $grid["r{$i}"]["c{$j}"] = Money::of(sprintf('%.6F', $micros / 1_000_000), 'GBP');
                }
            }

            self::assertGridAddsUp($grid, ShownAmounts::grid($grid, 'GBP'), "case {$case}");
        }
    }

    /**
     * @param array<string, array<string, Money>> $grid
     * @param array{
     *     cells: array<string, array<string, Money>>,
     *     rows: array<string, Money>,
     *     columns: array<string, Money>,
     *     total: Money,
     * } $shown
     */
    private static function assertGridAddsUp(array $grid, array $shown, string $at = ''): void
    {
        $exact = 0;
        $columns = [];
        foreach ($grid as $r => $row) {
            $sum = 0;
            foreach ($row as $c => $cell) {
                $cellShown = $shown['cells'][$r][$c]->micros;
                self::assertLessThan(10_000, abs($cellShown - $cell->micros), "within a penny, {$r} {$c} {$at}");
                if ($cell->micros % 10_000 === 0) {
                    self::assertSame($cell->micros, $cellShown, "whole pennies stay, {$at}");
                }
                $sum += $cellShown;
                $columns[$c] = ($columns[$c] ?? 0) + $cellShown;
                $exact += $cell->micros;
            }
            self::assertSame($shown['rows'][$r]->micros, $sum, "row {$r} {$at}");
        }
        foreach ($columns as $c => $sum) {
            self::assertSame($shown['columns'][$c]->micros, $sum, "column {$c} {$at}");
        }
        $rounded = ShownAmounts::round(Money::of(sprintf('%.6F', $exact / 1_000_000), 'GBP'));
        self::assertSame($rounded->micros, $shown['total']->micros, $at);
        self::assertSame(array_sum($columns), $shown['total']->micros, $at);
    }

    /**
     * @template K of array-key
     * @param array<K, Money> $amounts
     * @return array<K, string>
     */
    private static function pence(array $amounts): array
    {
        return array_map(static fn (Money $m): string => $m->toDecimal(2), $amounts);
    }
}
