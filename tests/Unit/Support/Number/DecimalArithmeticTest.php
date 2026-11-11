<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use InvalidArgumentException;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalArithmeticTest extends TestCase
{
    public function testAddAndSubtractAreExact(): void
    {
        self::assertSame('0.3', Decimal::add('0.1', '0.2'));
        self::assertSame('12.345', Decimal::add('12', '0.345'));
        self::assertSame('-0.500', Decimal::subtract('1.250', '1.75'));
        self::assertSame('312.000', Decimal::subtract('48412.000', '48100'));
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function products(): iterable
    {
        yield 'pump total' => ['45.12', '1.459', 2, '65.83'];
        yield 'half rounds away from zero' => ['1.5', '1.003', 3, '1.505'];
        yield 'negative half' => ['-1.5', '1.003', 3, '-1.505'];
        yield 'gallons to litres' => ['13.207', '3.785411784', 3, '49.994'];
        yield 'miles to km' => ['12345.6', '1.609344', 3, '19868.317'];
        yield 'more places than given' => ['2', '3', 2, '6.00'];
        yield 'zero' => ['0', '1.459', 2, '0.00'];
        yield 'yen' => ['40', '171.5', 0, '6860'];
    }

    #[DataProvider('products')]
    public function testMultiply(string $a, string $b, int $scale, string $expected): void
    {
        self::assertSame($expected, Decimal::multiply($a, $b, $scale));
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function quotients(): iterable
    {
        yield 'price from total' => ['65.83', '45.12', 6, '1.458998'];
        yield 'volume from total' => ['62.01', '1.459', 3, '42.502'];
        yield 'per US gallon to per litre' => ['3.499', '3.785411784', 6, '0.924338'];
        yield 'half rounds away from zero' => ['1', '8', 2, '0.13'];
        yield 'negative' => ['-1', '8', 2, '-0.13'];
        yield 'exact' => ['10', '4', 1, '2.5'];
        yield 'third' => ['1', '3', 6, '0.333333'];
        yield 'two thirds' => ['2', '3', 6, '0.666667'];
        yield 'scale below the inputs' => ['100.125', '2.5', 0, '40'];
    }

    #[DataProvider('quotients')]
    public function testDivide(string $a, string $b, int $scale, string $expected): void
    {
        self::assertSame($expected, Decimal::divide($a, $b, $scale));
    }

    public function testDivisionByZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::divide('1', '0', 2);
    }

    public function testHugeIntermediatesFallBackWithoutOverflow(): void
    {
        // 10^9 × 10^9 at scale 6 does not fit an int as micro-units.
        self::assertSame('1000000000000000000.000000', Decimal::multiply('999999999.999999', '1000000000.000001', 6));
        self::assertSame('0.500000', Decimal::divide('500000000000.123', '1000000000000', 6));
    }

    public function testProductsPastAnIntAreExact(): void
    {
        // K62: floats gave …299550; the exact product is -834951054.299549387792.
        self::assertSame('-834951054.299549', Decimal::multiply('-27094.791536', '30815.924647', 6));
        // A tie rounds away from zero, as the integer path does.
        self::assertSame('-12345678913595678901.3', Decimal::multiply('-123456789012.5', '100000000.1', 1));
        self::assertSame('12345678913595678901.3', Decimal::multiply('123456789012.5', '100000000.1', 1));
    }

    public function testQuotientsPastAnIntAreExact(): void
    {
        // The numerator × 10^12 overflows; floats gave …620811462402.
        self::assertSame('17636684144.620811142857', Decimal::divide('123456789012.345678', '7', 12));
        // Here the denominator × 10^9 overflows instead.
        self::assertSame('-0.1', Decimal::divide('-98765432.1987654321', '1234567890', 1));
        self::assertSame('0.1', Decimal::divide('98765432.1987654321', '1234567890', 1));
    }

    public function testLongScalesRoundedToFewPlacesNeverOverflow(): void
    {
        // Scales 9 + 12 rounded to 2: dividing by 10^19 is beyond an int.
        // A fuel cost of 0 per km made *Coming up* fail this way (Phase 24).
        self::assertSame('0.00', Decimal::multiply('1033.046375995', '0.000000000000', 2));
        self::assertSame('0.00', Decimal::multiply('-1033.046375995', '0.000000000000', 2));
        self::assertSame('0.13', Decimal::multiply('1033.046375995', '0.000123456789', 2));
    }

    public function testOperandsOfMoreThanEighteenDigitsAreExact(): void
    {
        // K187: a mistyped odometer's distance × a month's seconds, then ÷ the
        // segment's seconds, made the fuel page fail before the fallback.
        self::assertSame('720604.230000', Decimal::divide('1867806164160.000000', '2592000', 6));
        self::assertSame('-3703703670370.37', Decimal::multiply('-1234567890123.456789', '3', 2));
        self::assertSame('-3703703670370.37', Decimal::multiply('3', '-1234567890123.456789', 2));
        self::assertSame('0.000000000007', Decimal::divide('7', '1000000000000.000001', 12));
    }

    public function testALongNonCanonicalOperandIsStillRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::multiply('1234567890123456789e3', '2', 2);
    }

    public function testALongNumeratorOverZeroIsTheUsualRefusal(): void
    {
        // brick/math's own DivisionByZeroException must not leak out.
        $this->expectException(InvalidArgumentException::class);
        Decimal::divide('1234567890123456789', '0', 2);
    }

    public function testFortyDigitsStillWork(): void
    {
        $forty = str_repeat('1234567890', 4);
        self::assertSame($forty . '.00', Decimal::multiply($forty, '1', 2));
        self::assertSame('12345678901234567890123456789012345678.90', Decimal::divide($forty, '100', 2));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absurdOperands(): iterable
    {
        yield 'a 41-digit whole number' => ['1' . str_repeat('0', 40)];
        // The security review's case: an API distance in miles with 30,000
        // decimals took seconds of CPU in pure-PHP brick/math.
        yield 'a 30,000-digit fraction' => ['1.' . str_repeat('9', 30000)];
        yield 'a negative one' => ['-' . str_repeat('9', 60000)];
    }

    #[DataProvider('absurdOperands')]
    public function testAbsurdlyLongOperandsAreRefusedAtOnce(string $operand): void
    {
        foreach (
            [
                static fn () => Decimal::multiply($operand, '1.609344', 3),
                static fn () => Decimal::multiply('1.609344', $operand, 3),
                static fn () => Decimal::divide($operand, '4.54609', 3),
                static fn () => Decimal::divide('4.54609', $operand, 3),
            ] as $call
        ) {
            $started = hrtime(true);
            try {
                $call();
                self::fail('An operand past 40 digits is refused.');
            } catch (\OverflowException) {
                self::assertLessThan(100_000_000, hrtime(true) - $started, 'refused at once, not after seconds');
            }
        }
    }

    public function testTooLongSaysWhatMultiplyAndDivideWouldRefuse(): void
    {
        self::assertFalse(Decimal::tooLong(str_repeat('9', 40)));
        self::assertFalse(Decimal::tooLong('0.000' . str_repeat('9', 40)), 'leading zeros are not digits');
        self::assertTrue(Decimal::tooLong(str_repeat('9', 41)));
        self::assertTrue(Decimal::tooLong('-1.' . str_repeat('0', 40)));
        self::assertFalse(Decimal::tooLong(str_repeat('9', 41) . 'x'), 'not a number: parsing refuses it');
    }
}
