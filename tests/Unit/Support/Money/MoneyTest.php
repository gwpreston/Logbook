<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Money;

use InvalidArgumentException;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testZeroIsAValidAmount(): void
    {
        $zero = Money::of('0', 'GBP');

        self::assertTrue($zero->isZero());
        self::assertFalse($zero->isNegative());
        self::assertTrue($zero->equals(Money::zero('GBP')));
        self::assertSame('0.000', $zero->toDecimal(3));
    }

    public function testArithmeticIsExact(): void
    {
        // 0.1 + 0.2 is not 0.3 in floating point; it must be here.
        $sum = Money::of('0.1', 'EUR')->add(Money::of('0.2', 'EUR'));

        self::assertTrue($sum->equals(Money::of('0.3', 'EUR')));
        self::assertSame('0.30', $sum->toDecimal(2));
        self::assertSame('-0.100', Money::of('0.1', 'EUR')->subtract(Money::of('0.2', 'EUR'))->toDecimal(3));
    }

    public function testKeepsAtLeastThreeDecimalsForUnitPrices(): void
    {
        self::assertSame('1.459', Money::of('1.459', 'GBP')->toDecimal(3));
        self::assertSame('1.4599', Money::of('1.4599', 'GBP')->toDecimal(4));
        self::assertSame('1.460', Money::of('1.4599', 'GBP')->toDecimal(3));
    }

    public function testRefusesToMixCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1', 'GBP')->add(Money::of('1', 'EUR'));
    }

    public function testRejectsInvalidCurrencyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1', 'pounds');
    }

    public function testCurrencyResolvesVehicleThenUserThenApp(): void
    {
        self::assertSame('EUR', Currency::resolve('EUR', 'GBP', 'USD'));
        self::assertSame('GBP', Currency::resolve(null, 'GBP', 'USD'));
        self::assertSame('USD', Currency::resolve(null, null, 'USD'));
        // An unsupported override is ignored rather than trusted.
        self::assertSame('GBP', Currency::resolve('XXX', 'GBP', 'USD'));
    }

    public function testCurrencyMetadataComesFromIcu(): void
    {
        self::assertSame(2, Currency::fractionDigits('GBP'));
        self::assertSame(0, Currency::fractionDigits('JPY'));
        self::assertSame(3, Currency::fractionDigits('BHD'));
        self::assertSame('British Pound', Currency::name('GBP', 'en_GB'));
        self::assertSame('£', Currency::symbol('GBP', 'en_GB'));
        self::assertTrue(Currency::isSupported('EUR'));
        self::assertFalse(Currency::isSupported('eur'));
    }
}
