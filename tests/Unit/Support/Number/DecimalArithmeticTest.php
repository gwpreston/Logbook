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
}
