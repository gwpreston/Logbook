<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\DecimalSum;
use PHPUnit\Framework\TestCase;

/**
 * A running total (Phase 54.5, K149) reads exactly as adding the values one
 * by one with Decimal::add() from '0', whatever their places and signs, and
 * past the point where it leaves the integers.
 */
final class DecimalSumTest extends TestCase
{
    public function testItReadsAsAddingOneByOne(): void
    {
        mt_srand(545);
        for ($run = 0; $run < 200; $run++) {
            $sum = new DecimalSum();
            $expected = '0';
            for ($i = 0, $n = mt_rand(0, 40); $i < $n; $i++) {
                $places = mt_rand(0, 6);
                $value = number_format(mt_rand(-50000, 90000) / 7, $places, '.', '');
                $value = $value === '-0' || preg_match('/^-0\.0*$/', $value) === 1 ? ltrim($value, '-') : $value;
                $sum->add($value);
                $expected = Decimal::add($expected, $value);
                self::assertSame($expected, $sum->value(), "run $run after $value");
            }
            self::assertSame($expected, $sum->value());
        }
    }

    public function testNothingAddedIsZero(): void
    {
        self::assertSame('0', (new DecimalSum())->value());
        $sum = new DecimalSum();
        $sum->add('0.000');
        self::assertSame('0.000', $sum->value());
    }

    public function testALargeTotalCarriesOnInStrings(): void
    {
        $values = ['99999999999999.999', '0.5', '99999999999999.999', '-12.25', '1', '12.001'];
        $sum = new DecimalSum();
        $expected = '0';
        foreach ($values as $value) {
            $sum->add($value);
            $expected = Decimal::add($expected, $value);
            self::assertSame($expected, $sum->value(), $value);
        }
    }
}
