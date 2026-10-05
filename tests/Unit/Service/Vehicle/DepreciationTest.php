<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\DepreciationState;
use Logbook\Service\Vehicle\ValuePointKind;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Units\DistanceUnit;
use PHPUnit\Framework\TestCase;

final class DepreciationTest extends TestCase
{
    /** 36,000 miles in kilometres. */
    private const string MILES_36K = '57936.384';

    public function testTheWorkedExample(): void
    {
        // Bought for £15,000 on 1 Mar 2023, valued at £9,800 on 1 Mar 2026, 36,000 mi between.
        $vehicle = self::vehicle(purchased: '2023-03-01', price: '15000.000');
        $readings = [
            self::reading(1, '10000', '2023-03-01T12:00:00Z'),
            self::reading(2, '40000', '2024-09-01T12:00:00Z'),
            self::reading(3, (string) (10000 + (float) self::MILES_36K), '2026-03-01T12:00:00Z'),
        ];

        $result = self::of($vehicle, [self::valuation(1, '2026-03-01', '9800.000', 'Auto Trader valuation')], $readings);

        self::assertSame(DepreciationState::Ready, $result->state);
        self::assertSame('-5200.000', $result->change);
        self::assertSame('-0.346667', $result->fraction, '−34.67%');
        self::assertSame('1733.333', $result->perYear, '£1,733.33 per year over exactly three years');
        self::assertNotNull($result->perKm);
        self::assertEqualsWithDelta(0.144, (float) $result->perKm * DistanceUnit::KM_PER_MILE, 0.0005, '£0.144 per mile');
        self::assertFalse($result->isGain());
        self::assertFalse($result->isSold());
        self::assertSame('GBP', $result->currency);
        self::assertSame('Auto Trader valuation', $result->current?->source);
    }

    public function testTheSalePriceOverridesTheLatestValuation(): void
    {
        $vehicle = self::vehicle(purchased: '2023-03-01', price: '15000.000', sold: '2026-06-01', salePrice: '9000.000');

        $result = self::of($vehicle, [self::valuation(1, '2026-03-01', '9800.000')]);

        self::assertTrue($result->isSold());
        self::assertSame(ValuePointKind::Sold, $result->current?->kind);
        self::assertSame('-6000.000', $result->change);
        self::assertNull($result->staleMonths, 'a sold vehicle is never stale');
    }

    public function testAGainHasNoPerYearButANegativePerDistance(): void
    {
        $vehicle = self::vehicle(purchased: '2020-03-01', price: '12000.000');
        $readings = [self::reading(1, '1000', '2020-03-01T12:00:00Z'), self::reading(2, '30000', '2026-03-01T12:00:00Z')];

        $result = self::of($vehicle, [self::valuation(1, '2026-03-01', '13100.000')], $readings);

        self::assertTrue($result->isGain());
        self::assertSame('1100.000', $result->change);
        self::assertSame('0.091667', $result->fraction, '+9%');
        self::assertNull($result->perYear);
        self::assertSame('-0.037931', $result->perKm, '£1,100 gained over 29,000 km (#154)');
    }

    public function testPerYearAndPerDistanceNeedNinetyDays(): void
    {
        $vehicle = self::vehicle(purchased: '2026-01-01', price: '10000.000');
        $readings = [self::reading(1, '1000', '2026-01-01T12:00:00Z'), self::reading(2, '5000', '2026-04-01T12:00:00Z')];

        // 1 Jan → 31 Mar is 89 days; → 1 Apr is 90.
        $early = self::of($vehicle, [self::valuation(1, '2026-03-31', '9000.000')], $readings);
        self::assertSame('-1000.000', $early->change, 'the change itself is always shown');
        self::assertNull($early->perYear);
        self::assertNull($early->perKm);

        $ready = self::of($vehicle, [self::valuation(1, '2026-04-01', '9000.000')], $readings);
        self::assertNotNull($ready->perYear);
        self::assertSame('0.250000', $ready->perKm, '£1,000 over 4,000 km, the reading on the value date counted');
    }

    public function testNoPerDistanceWithoutDistanceDriven(): void
    {
        $vehicle = self::vehicle(purchased: '2024-01-01', price: '10000.000');

        $readings = [self::reading(1, '1000', '2024-06-01T12:00:00Z')];

        $result = self::of($vehicle, [self::valuation(1, '2025-01-01', '8000.000')], $readings);

        self::assertNotNull($result->perYear);
        self::assertNull($result->perKm);
    }

    public function testTheDistanceIsMeasuredToTheValuesDateNotToday(): void
    {
        $vehicle = self::vehicle(purchased: '2024-01-01', price: '10000.000');
        $readings = [
            self::reading(1, '0', '2024-01-01T12:00:00Z'),
            self::reading(2, '10000', '2025-01-01T12:00:00Z'),
            self::reading(3, '50000', '2026-01-01T12:00:00Z'),
        ];

        $result = self::of($vehicle, [self::valuation(1, '2025-01-01', '8000.000')], $readings);

        self::assertSame('0.200000', $result->perKm, '£2,000 over the 10,000 km to the valuation');
        self::assertSame('2000.000', $result->perYear, 'one year, not the time to today');
    }

    public function testNoPerDistanceWhenTheMileageStartsAfterThePurchase(): void
    {
        // Bought in 2021, first logged in 2025: part of the distance, not all of it.
        $vehicle = self::vehicle(purchased: '2021-03-14', price: '14250.000');
        $readings = [self::reading(1, '61680', '2025-09-28T11:00:00Z'), self::reading(2, '70000', '2026-03-14T11:00:00Z')];

        $result = self::of($vehicle, [self::valuation(1, '2026-03-14', '9800.000')], $readings);

        self::assertNotNull($result->perYear, 'the per-year figure needs no mileage');
        self::assertNull($result->perKm);
    }

    public function testPerDistanceStartsFromTheLastReadingBeforeThePurchase(): void
    {
        $vehicle = self::vehicle(purchased: '2024-01-10', price: '10000.000');
        $readings = [
            self::reading(1, '5000', '2023-12-01T12:00:00Z'),
            self::reading(2, '6000', '2024-01-05T12:00:00Z'),
            self::reading(3, '16000', '2025-01-10T12:00:00Z'),
        ];

        $result = self::of($vehicle, [self::valuation(1, '2025-01-10', '8000.000')], $readings);

        self::assertSame('0.200000', $result->perKm, '£2,000 over the 10,000 km from the reading just before the purchase');
    }

    public function testThePriceAloneGivesTheChangeButNotTheRates(): void
    {
        $vehicle = self::vehicle(purchased: null, price: '15000.000');

        $result = self::of($vehicle, [self::valuation(1, '2025-01-01', '9000.000')]);

        self::assertSame(DepreciationState::Ready, $result->state);
        self::assertSame('-6000.000', $result->change);
        self::assertNull($result->perYear);
        self::assertNull($result->perKm);
        self::assertCount(1, $result->points, 'no *Bought* point without its date');
    }

    public function testAPurchasePriceOfZeroHasNoPercentage(): void
    {
        $vehicle = self::vehicle(purchased: '2020-01-01', price: '0.000');

        $result = self::of($vehicle, [self::valuation(1, '2025-01-01', '500.000')]);

        self::assertSame('500.000', $result->change);
        self::assertNull($result->fraction);
    }

    public function testTheStatesWithoutAPriceOrAValue(): void
    {
        self::assertSame(
            DepreciationState::NoPurchasePrice,
            self::of(self::vehicle(purchased: '2020-01-01', price: null), [self::valuation(1, '2025-01-01', '500.000')])->state,
        );
        $priced = self::vehicle(purchased: '2020-01-01', price: '9000.000');
        self::assertSame(DepreciationState::NoValue, self::of($priced, [])->state);
        self::assertSame(
            DepreciationState::NoValue,
            self::of(self::vehicle(purchased: '2020-01-01', price: '9000.000', sold: '2025-01-01'), [])->state,
            'a sale date without a price is no value',
        );
    }

    public function testAValuationIsStaleAfterTwelveMonthsAndOneDay(): void
    {
        $vehicle = self::vehicle(purchased: '2020-01-01', price: '9000.000');
        $valuations = [self::valuation(1, '2025-03-15', '5000.000')];

        self::assertNull(self::of($vehicle, $valuations, today: '2026-03-15')->staleMonths, 'exactly twelve months');
        self::assertSame(12, self::of($vehicle, $valuations, today: '2026-03-16')->staleMonths);
        self::assertSame(14, self::of($vehicle, $valuations, today: '2026-05-20')->staleMonths);
        self::assertSame('-4000.000', self::of($vehicle, $valuations, today: '2026-05-20')->change, 'the figures still show');
    }

    public function testTheCurrencyIsNeverConverted(): void
    {
        $vehicle = self::vehicle(purchased: '2020-01-01', price: '2000000.000');

        $result = self::of($vehicle, [self::valuation(1, '2025-01-01', '1500000.000')], currency: 'JPY');

        self::assertSame('JPY', $result->currency);
        self::assertSame('-500000.000', $result->change);
    }

    public function testTheSeriesOrderOnOneDay(): void
    {
        // Bought, valued twice and sold on the same day: bought first, sold last,
        // valuations in the order they were added.
        $vehicle = self::vehicle(purchased: '2026-01-10', price: '5000.000', sold: '2026-01-10', salePrice: '5200.000');
        $valuations = [
            self::valuation(8, '2026-01-10', '5100.000', 'second'),
            self::valuation(3, '2026-01-10', '5050.000', 'first'),
            self::valuation(9, '2025-12-01', '4000.000', 'earlier'),
        ];

        $points = Depreciation::series($vehicle, $valuations);

        self::assertSame(
            ['valuation:earlier', 'bought:', 'valuation:first', 'valuation:second', 'sold:'],
            array_map(static fn ($point): string => $point->kind->value . ':' . $point->source, $points),
        );
    }

    /**
     * @param list<VehicleValuation> $valuations
     * @param list<OdometerReading> $readings
     */
    private static function of(
        Vehicle $vehicle,
        array $valuations,
        array $readings = [],
        string $today = '2026-03-02',
        string $currency = 'GBP',
    ): Depreciation {
        $zone = new DateTimeZone('Europe/London');

        return Depreciation::of($vehicle, $valuations, $readings, self::date($today), $zone, $currency);
    }

    private static function vehicle(?string $purchased, ?string $price, ?string $sold = null, ?string $salePrice = null): Vehicle
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Vehicle(1, 1, new VehicleData(
            type: VehicleType::Car,
            make: 'Volkswagen',
            model: 'Golf',
            fuelType: FuelType::Petrol,
            purchaseDate: $purchased === null ? null : self::date($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::date($sold),
            salePrice: $salePrice,
        ), VehicleStatus::Active, null, null, null, $now, $now);
    }

    private static function valuation(int $id, string $on, string $amount, ?string $source = null): VehicleValuation
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new VehicleValuation($id, 1, new VehicleValuationData(self::date($on), $amount, $source), $now, $now);
    }

    private static function reading(int $id, string $km, string $at): OdometerReading
    {
        $instant = new DateTimeImmutable($at);

        return new OdometerReading($id, 1, $km, $instant, OdometerSource::Manual, null, null, $instant, $instant);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
