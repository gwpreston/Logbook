<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use Brick\Math\BigRational;
use Logbook\Support\Number\Apportion;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parts rounded so they still add up (docs/phases/phase-32.md *What changed*).
 */
final class ApportionTest extends TestCase
{
    public function testThirdsAddUpToTheRoundedWhole(): void
    {
        $third = BigRational::ofFraction(1, 3);

        $parts = Apportion::toScale([$third, $third, $third], 2);

        self::assertSame(['0.34', '0.33', '0.33'], $parts, 'the first of equal remainders takes the spare unit');
        self::assertSame('1.00', self::sum($parts));
    }

    public function testNegativePartsFloorDownAndStillAddUp(): void
    {
        $parts = Apportion::toScale([BigRational::of('0.125'), BigRational::of('-0.0375'), BigRational::of('0.0125')], 2);

        self::assertSame('0.10', self::sum($parts), '0.1 exactly');
        self::assertSame(['0.13', '-0.04', '0.01'], $parts, '−0.0375 floors to −0.04; 0.125 has the largest remainder');
    }

    public function testATargetRoundedElsewhereIsMetExactly(): void
    {
        // Price +2.649… and economy −0.549… must add up to a fuel change of +2.10.
        $parts = Apportion::toTarget([BigRational::of('2.6494'), BigRational::of('-0.5496')], '2.10', 2);

        self::assertSame('2.10', self::sum($parts));
        self::assertSame(['2.65', '-0.55'], $parts);
    }

    public function testUnitsAreTakenAwayFromTheSmallestRemainders(): void
    {
        $parts = Apportion::toTarget([BigRational::of('1.004'), BigRational::of('1.001')], '1.99', 2);

        self::assertSame('1.99', self::sum($parts));
        self::assertSame(['1.00', '0.99'], $parts);
    }

    public function testNoPartsGiveNothing(): void
    {
        self::assertSame([], Apportion::toTarget([], '0', 6));
        self::assertSame([], Apportion::percentages([]));
    }

    /**
     * @return iterable<string, array{0: list<int>, 1: list<int>}>
     */
    public static function awkwardSplits(): iterable
    {
        yield 'thirds' => [[1, 1, 1], [34, 33, 33]];
        yield 'two-thirds and a third' => [[2, 1], [67, 33]];
        yield 'sevenths' => [[1, 1, 1, 1, 1, 1, 1], [15, 15, 14, 14, 14, 14, 14]];
        yield 'a tiny part' => [[999_000_000, 1_000_000], [100, 0]];
        yield 'the largest remainder wins' => [[125, 125, 750], [13, 12, 75]];
        yield 'one group' => [[42_500_000], [100]];
        yield 'money in micros' => [[61_420_000, 18_330_000, 420_000_000, 2_500_000], [12, 4, 84, 0]];
    }

    /**
     * Whole percentages for the expense breakdown (spec.md §7.8): always 100
     * between them, whatever the split.
     *
     * @param list<int> $parts
     * @param list<int> $expected
     */
    #[DataProvider('awkwardSplits')]
    public function testPercentagesAddUpToExactlyOneHundred(array $parts, array $expected): void
    {
        $percents = Apportion::percentages($parts);

        self::assertSame($expected, $percents);
        self::assertSame(100, array_sum($percents));
    }

    public function testAZeroTotalGivesNoShares(): void
    {
        self::assertSame([0, 0, 0], Apportion::percentages([0, 0, 0]));
    }

    /**
     * @param list<string> $parts
     */
    private static function sum(array $parts): string
    {
        $sum = '0.00';
        foreach ($parts as $part) {
            $sum = Decimal::add($sum, $part);
        }

        return $sum;
    }
}
