<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Units;

use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stored quantities are SI; what the user typed in their own units must come
 * back unchanged (≥3 decimals round-trip, CLAUDE.md §8).
 */
final class ExactConversionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function typedValues(): iterable
    {
        foreach (['0', '0.001', '1.5', '13.207', '12345.678', '99999.999', '48045.1'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('typedValues')]
    public function testMilesRoundTripThroughKilometres(string $miles): void
    {
        $km = DistanceUnit::Mile->toKmDecimal($miles, 3);

        self::assertSame(Decimal::round($miles, 3), DistanceUnit::Mile->fromKmDecimal($km, 3));
    }

    #[DataProvider('typedValues')]
    public function testGallonsRoundTripThroughLitres(string $gallons): void
    {
        foreach ([VolumeUnit::UkGallon, VolumeUnit::UsGallon, VolumeUnit::Litre] as $unit) {
            $litres = $unit->toLitresDecimal($gallons, 3);
            self::assertSame(Decimal::round($gallons, 3), $unit->fromLitresDecimal($litres, 3), $unit->value);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typedPrices(): iterable
    {
        foreach (['3.499', '1.459', '0.001', '5.2', '0', '0.2849', '1234.567'] as $price) {
            yield $price => [$price];
        }
    }

    #[DataProvider('typedPrices')]
    public function testPricePerGallonRoundTripsThroughPricePerLitre(string $price): void
    {
        foreach ([VolumeUnit::UkGallon, VolumeUnit::UsGallon] as $unit) {
            $perLitre = $unit->pricePerLitre($price, 6);
            self::assertSame(Decimal::round($price, 4), $unit->pricePerUnit($perLitre, 4), $unit->value);
        }
    }

    public function testKnownConversions(): void
    {
        self::assertSame('160.934', DistanceUnit::Mile->toKmDecimal('100', 3));
        self::assertSame('45.461', VolumeUnit::UkGallon->toLitresDecimal('10', 3));
        self::assertSame('37.854', VolumeUnit::UsGallon->toLitresDecimal('10', 3));
        // $3.499/US gal is 92.4 cents a litre.
        self::assertSame('0.924338', VolumeUnit::UsGallon->pricePerLitre('3.499', 6));
        self::assertSame('1.459', VolumeUnit::Litre->pricePerLitre('1.459', 3));
    }

    public function testElectricEfficiency(): void
    {
        // 300 km on 50 kWh: 16.7 kWh/100 km, 6 km/kWh, 3.73 mi/kWh.
        self::assertEqualsWithDelta(16.667, ElectricEfficiencyUnit::KwhPer100Km->fromDistanceAndEnergy(300.0, 50.0), 0.001);
        self::assertEqualsWithDelta(6.0, ElectricEfficiencyUnit::KmPerKwh->fromDistanceAndEnergy(300.0, 50.0), 1e-9);
        self::assertEqualsWithDelta(3.728, ElectricEfficiencyUnit::MilesPerKwh->fromDistanceAndEnergy(300.0, 50.0), 0.001);
        self::assertNull(ElectricEfficiencyUnit::MilesPerKwh->fromDistanceAndEnergy(0.0, 50.0));
        self::assertNull(ElectricEfficiencyUnit::KwhPer100Km->fromDistanceAndEnergy(300.0, 0.0));

        self::assertSame(ElectricEfficiencyUnit::KwhPer100Km, ElectricEfficiencyUnit::forDistanceUnit(DistanceUnit::Kilometre));
        self::assertSame(ElectricEfficiencyUnit::MilesPerKwh, ElectricEfficiencyUnit::forDistanceUnit(DistanceUnit::Mile));
        self::assertFalse(ElectricEfficiencyUnit::KwhPer100Km->higherIsBetter());
        self::assertTrue(ElectricEfficiencyUnit::MilesPerKwh->higherIsBetter());
    }
}
