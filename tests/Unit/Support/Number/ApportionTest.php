<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use Brick\Math\BigRational;
use Logbook\Support\Number\Apportion;
use Logbook\Support\Number\Decimal;
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
