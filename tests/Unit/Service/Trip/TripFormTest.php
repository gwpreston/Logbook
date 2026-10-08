<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Trip;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Trip\TripData;
use Logbook\Service\Trip\TripForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\TestCase;

/**
 * An edited trip keeps the stored km of whatever is submitted as the form
 * showed it (spec.md §8 *Units*), and converts whatever changed.
 */
final class TripFormTest extends TestCase
{
    public function testUnchangedOdometersKeepThemAndTheDistanceBetween(): void
    {
        $stored = self::trip(
            new TripData(self::day(), 'Ballymena', 'Belfast', false, '90.123', '40800.000', '40890.123', purpose: 'Site visit'),
        );
        $shown = TripForm::values($stored, self::miles());

        $kept = TripForm::parse(['notes' => 'Parking'] + $shown, self::miles(), self::day(), stored: $stored);
        self::assertInstanceOf(TripData::class, $kept);
        self::assertSame(['40800.000', '40890.123', '90.123'], [$kept->odometerStartKm, $kept->odometerEndKm, $kept->distanceKm]);

        $moved = TripForm::parse(['odometer_end' => '25408'] + $shown, self::miles(), self::day(), stored: $stored);
        self::assertInstanceOf(TripData::class, $moved);
        self::assertSame('40800.000', $moved->odometerStartKm);
        self::assertSame('40890.212', $moved->odometerEndKm, 'a changed odometer is converted');
        self::assertSame('90.212', $moved->distanceKm, 'and the distance is worked out again');
    }

    public function testAnUnchangedDistanceIsKeptOnlyWhileReturnIsUnchanged(): void
    {
        $stored = self::trip(new TripData(self::day(), 'Ballymena', 'Belfast', true, '40800.000', purpose: 'Site visit'));
        $shown = TripForm::values($stored, self::miles());
        self::assertSame('12675.973', $shown['distance'], 'one way, in miles');

        $kept = TripForm::parse($shown, self::miles(), self::day(), stored: $stored);
        self::assertInstanceOf(TripData::class, $kept);
        self::assertSame('40800.000', $kept->distanceKm);

        $new = TripForm::parse($shown, self::miles(), self::day());
        self::assertInstanceOf(TripData::class, $new);
        self::assertSame('40800.002', $new->distanceKm, 'a new trip converts the figure typed');

        $oneWay = TripForm::parse(['is_return' => ''] + $shown, self::miles(), self::day(), stored: $stored);
        self::assertInstanceOf(TripData::class, $oneWay);
        self::assertSame('20400.001', $oneWay->distanceKm, 'no longer a return: the same figure means half the trip');

        $odometers = ['odometer_start' => '100', 'odometer_end' => '125', 'distance' => ''] + $shown;
        $withOdometers = TripForm::parse($odometers, self::miles(), self::day(), stored: $stored);
        self::assertInstanceOf(TripData::class, $withOdometers);
        self::assertSame('40.234', $withOdometers->distanceKm, 'odometers added: the distance is theirs');
    }

    private static function trip(TripData $data): Trip
    {
        return new Trip(1, 1, $data, self::day(), self::day());
    }

    private static function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC'));
    }

    private static function miles(): DisplayPreferences
    {
        return new DisplayPreferences(
            'en_GB',
            'Europe/London',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'GBP',
        );
    }
}
