<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Units;

use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function testDistance(): void
    {
        self::assertSame(100.0, DistanceUnit::Kilometre->fromKm(100.0));
        self::assertEqualsWithDelta(62.137, DistanceUnit::Mile->fromKm(100.0), 0.001);
        self::assertEqualsWithDelta(160.9344, DistanceUnit::Mile->toKm(100.0), 1e-9);
    }

    public function testVolume(): void
    {
        self::assertEqualsWithDelta(4.54609, VolumeUnit::UkGallon->toLitres(1.0), 1e-12);
        self::assertEqualsWithDelta(3.785411784, VolumeUnit::UsGallon->toLitres(1.0), 1e-12);
        self::assertEqualsWithDelta(10.0, VolumeUnit::UkGallon->fromLitres(45.4609), 1e-9);
    }

    /**
     * 5 L/100km is a well-known reference point: 20 km/L, 56.5 mpg (UK), 47.0 mpg (US).
     *
     * @return iterable<string, array{ConsumptionUnit, float}>
     */
    public static function fiveLitresPer100Km(): iterable
    {
        yield 'L/100km' => [ConsumptionUnit::LitresPer100Km, 5.0];
        yield 'km/L' => [ConsumptionUnit::KmPerLitre, 20.0];
        yield 'mpg (UK)' => [ConsumptionUnit::MpgUk, 56.496];
        yield 'mpg (US)' => [ConsumptionUnit::MpgUs, 47.043];
    }

    #[DataProvider('fiveLitresPer100Km')]
    public function testConsumptionConversions(ConsumptionUnit $unit, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $unit->fromLitresPer100Km(5.0), 0.001);
        self::assertEqualsWithDelta(5.0, $unit->toLitresPer100Km($expected), 0.001);
        // 400 km on 20 L is 5 L/100km.
        self::assertEqualsWithDelta($expected, $unit->fromDistanceAndVolume(400.0, 20.0), 0.001);
    }

    public function testUkAndUsMpgDiffer(): void
    {
        $uk = ConsumptionUnit::MpgUk->fromLitresPer100Km(7.0);
        $us = ConsumptionUnit::MpgUs->fromLitresPer100Km(7.0);

        self::assertEqualsWithDelta(40.35, $uk, 0.01);
        self::assertEqualsWithDelta(33.60, $us, 0.01);
        // The ratio is exactly the ratio of the gallons.
        self::assertEqualsWithDelta(VolumeUnit::LITRES_PER_UK_GALLON / VolumeUnit::LITRES_PER_US_GALLON, $uk / $us, 1e-12);
    }

    public function testNoConsumptionForZeroDistanceOrVolume(): void
    {
        self::assertNull(ConsumptionUnit::MpgUk->fromDistanceAndVolume(0.0, 40.0));
        self::assertNull(ConsumptionUnit::LitresPer100Km->fromDistanceAndVolume(500.0, 0.0));
    }

    public function testHigherIsBetterOnlyForDistancePerVolume(): void
    {
        self::assertFalse(ConsumptionUnit::LitresPer100Km->higherIsBetter());
        self::assertTrue(ConsumptionUnit::MpgUs->higherIsBetter());
    }

    public function testPresets(): void
    {
        self::assertSame(DistanceUnit::Mile, UnitPreset::Uk->distance());
        self::assertSame(VolumeUnit::Litre, UnitPreset::Uk->volume());
        self::assertSame(ConsumptionUnit::MpgUk, UnitPreset::Uk->consumption());
        self::assertSame(VolumeUnit::UsGallon, UnitPreset::Us->volume());
        self::assertSame(
            UnitPreset::Metric,
            UnitPreset::matching(DistanceUnit::Kilometre, VolumeUnit::Litre, ConsumptionUnit::LitresPer100Km),
        );
        self::assertNull(UnitPreset::matching(DistanceUnit::Kilometre, VolumeUnit::Litre, ConsumptionUnit::MpgUk));
    }

    /**
     * Values typed with 3 decimals in any unit survive the trip to SI storage
     * at 3 decimals and back.
     *
     * @return iterable<string, array{VolumeUnit|DistanceUnit, string}>
     */
    public static function roundTrips(): iterable
    {
        yield 'UK gallons' => [VolumeUnit::UkGallon, '9.874'];
        yield 'US gallons' => [VolumeUnit::UsGallon, '13.207'];
        yield 'miles' => [DistanceUnit::Mile, '123456.789'];
        yield 'small miles' => [DistanceUnit::Mile, '0.001'];
    }

    #[DataProvider('roundTrips')]
    public function testThreeDecimalInputsRoundTripThroughStorage(VolumeUnit|DistanceUnit $unit, string $input): void
    {
        $stored = Decimal::fromFloat(
            $unit instanceof VolumeUnit ? $unit->toLitres((float) $input) : $unit->toKm((float) $input),
            3,
        );
        $back = $unit instanceof VolumeUnit ? $unit->fromLitres((float) $stored) : $unit->fromKm((float) $stored);

        self::assertSame($input, Decimal::fromFloat($back, 3));
    }
}
