<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use Logbook\Service\Fuel\FuelAmounts;
use PHPUnit\Framework\TestCase;

/**
 * Any two of volume, price per unit and total derive the third, exactly.
 */
final class FuelAmountsTest extends TestCase
{
    public function testVolumeAndPriceGiveTheTotalRoundedToTheCurrency(): void
    {
        $amounts = FuelAmounts::complete('45.12', '1.459', null, 2);

        self::assertInstanceOf(FuelAmounts::class, $amounts);
        self::assertSame('65.83', $amounts->total, '45.12 × 1.459 = 65.83008');
        self::assertSame('1.459', $amounts->pricePerUnit);

        // Yen have no minor unit; Bahraini dinar three.
        $yen = FuelAmounts::complete('40', '171.5', null, 0);
        self::assertInstanceOf(FuelAmounts::class, $yen);
        self::assertSame('6860', $yen->total);
        $dinar = FuelAmounts::complete('40.5', '0.205', null, 3);
        self::assertInstanceOf(FuelAmounts::class, $dinar);
        self::assertSame('8.303', $dinar->total);
    }

    public function testVolumeAndTotalGiveThePrice(): void
    {
        $amounts = FuelAmounts::complete('42.5', null, '62.01', 2);

        self::assertInstanceOf(FuelAmounts::class, $amounts);
        self::assertSame('1.459059', $amounts->pricePerUnit);
    }

    public function testPriceAndTotalGiveTheVolume(): void
    {
        $amounts = FuelAmounts::complete(null, '1.459', '62.01', 2);

        self::assertInstanceOf(FuelAmounts::class, $amounts);
        self::assertSame('42.502', $amounts->volume);
    }

    public function testAllThreeAreKeptAsEntered(): void
    {
        // A 5p/L loyalty discount: the total is what was paid.
        $amounts = FuelAmounts::complete('40.000', '1.459', '56.36', 2);

        self::assertInstanceOf(FuelAmounts::class, $amounts);
        self::assertSame(['40.000', '1.459', '56.36'], [$amounts->volume, $amounts->pricePerUnit, $amounts->total]);
    }

    public function testThreeDecimalInputsSurvive(): void
    {
        $amounts = FuelAmounts::complete('12.345', '3.499', null, 2);

        self::assertInstanceOf(FuelAmounts::class, $amounts);
        self::assertSame('12.345', $amounts->volume);
        self::assertSame('3.499', $amounts->pricePerUnit);
        self::assertSame('43.20', $amounts->total, '12.345 × 3.499 = 43.195155');
    }

    public function testZeroCostIsValid(): void
    {
        // Free charging at work.
        $free = FuelAmounts::complete('30', '0', null, 2);
        self::assertInstanceOf(FuelAmounts::class, $free);
        self::assertSame('0.00', $free->total);

        $freeTotal = FuelAmounts::complete('30', null, '0', 2);
        self::assertInstanceOf(FuelAmounts::class, $freeTotal);
        self::assertSame('0.000000', $freeTotal->pricePerUnit);
    }

    public function testWhatCannotBeDerived(): void
    {
        self::assertSame(FuelAmounts::NEED_TWO, FuelAmounts::complete('40', null, null, 2));
        self::assertSame(FuelAmounts::NEED_TWO, FuelAmounts::complete(null, null, '60', 2));
        self::assertSame(FuelAmounts::NEED_TWO, FuelAmounts::complete(null, null, null, 2));
        // A zero price says nothing about how much went in.
        self::assertSame(FuelAmounts::VOLUME_UNKNOWN, FuelAmounts::complete(null, '0', '10', 2));
        // A total too small to be a volume at this price.
        self::assertSame(FuelAmounts::VOLUME_UNKNOWN, FuelAmounts::complete(null, '9999', '0.001', 2));
    }
}
