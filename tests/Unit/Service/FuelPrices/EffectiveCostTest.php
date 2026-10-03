<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\FuelPrices\EffectiveCost;
use Logbook\Service\FuelPrices\VehicleFuelProfile;
use Logbook\Service\FuelPrices\WorthIt;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Effective cost and "Was it worth it?" (spec.md §7.34): the usual fill at
 * the listed price plus the fuel to get there and back (2 × straight line
 * × 1.3) at the vehicle's economy, and the sum against the nearest.
 */
final class EffectiveCostTest extends TestCase
{
    private const float MILE = 1.609344;
    /** 48 mpg (UK): one gallon (4.54609 L) per 48 miles. */
    private const string KM_48 = '77.248512';
    private const string GALLON = '4.54609';

    /**
     * The spec's worked example: a station 7 mi further away, 4p cheaper, a
     * 50 L usual fill at 48 mpg.
     */
    public function testTheWorkedExampleIsNotWorthTheTrip(): void
    {
        $profile = $this->profile('50', self::KM_48, self::GALLON);
        $nearest = EffectiveCost::of(0.0, '1.399', $profile);
        $farther = EffectiveCost::of(7 * self::MILE, '1.359', $profile);

        $sum = WorthIt::compare($nearest, $farther);

        self::assertSame('2.00', Decimal::round($sum->fuelSaving, 2), 'Fuel saving £2.00 (50 L at 4p less)');
        self::assertSame('0.040', $sum->priceDifference);
        self::assertSame(14.0, round($sum->extraKm / self::MILE, 1), 'Extra distance 14 mi there and back');
        self::assertSame(18.2, round($sum->extraRoadKm / self::MILE, 1), 'about 18 mi by road');
        self::assertSame('2.34', Decimal::round($sum->fuelForThat, 2), 'Fuel for that £2.34 at your usual 48 mpg');
        self::assertSame('-0.34', Decimal::round($sum->actualSaving, 2), 'Actual saving −£0.34');
        self::assertFalse($sum->isWorthIt(), 'not worth the trip');

        // The ranking agrees: the nearer station costs less in all.
        self::assertLessThan(0, Decimal::compare($nearest->total, $farther->total));
    }

    public function testACheaperStationAShortWayOffWins(): void
    {
        $profile = $this->profile('50', self::KM_48, self::GALLON);
        $nearest = EffectiveCost::of(0.5, '1.399', $profile);
        $cheaper = EffectiveCost::of(2.5, '1.349', $profile);

        $sum = WorthIt::compare($nearest, $cheaper);

        self::assertSame('2.50', Decimal::round($sum->fuelSaving, 2));
        self::assertTrue($sum->isWorthIt());
        self::assertSame('2.09', Decimal::round($sum->actualSaving, 2));
        self::assertGreaterThan(0, Decimal::compare($nearest->total, $cheaper->total));
    }

    public function testTheDetourIsTwiceTheStraightLineTimesTheRoadFactor(): void
    {
        self::assertSame(1.3, EffectiveCost::ROAD_FACTOR);
        self::assertEqualsWithDelta(26.0, EffectiveCost::detour(10.0), 1e-9);

        $cost = EffectiveCost::of(10.0, '1.500', $this->profile('40', '100', '6'));
        self::assertEqualsWithDelta(26.0, $cost->detourKm, 1e-9);
        self::assertSame('1.5600', $cost->detourLitres, '26 km at 6 L/100 km');
        self::assertSame('60.0000', $cost->fillCost);
        self::assertSame('2.3400', $cost->detourCost);
        self::assertSame('62.3400', $cost->total);
    }

    public function testWithoutAnEconomyTheDriveIsNotCounted(): void
    {
        $profile = $this->profile('40', null, null, assumed: true);
        $cost = EffectiveCost::of(5.0, '1.389', $profile);

        self::assertFalse($profile->hasEconomy());
        self::assertNull($cost->detourLitres);
        self::assertSame('0', $cost->detourCost);
        self::assertSame('55.5600', $cost->total, '40 L at £1.389, the assumed fill');
        self::assertSame(VehicleFuelProfile::DEFAULT_FILL, $profile->usualFill);
    }

    private function profile(string $fill, ?string $km, ?string $litres, bool $assumed = false): VehicleFuelProfile
    {
        $now = new DateTimeImmutable('2026-10-03T00:00:00Z');

        return new VehicleFuelProfile(
            new Vehicle(
                1,
                1,
                new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol),
                VehicleStatus::Active,
                null,
                null,
                null,
                $now,
                $now,
            ),
            $fill,
            $assumed,
            $km,
            $litres,
        );
    }
}
