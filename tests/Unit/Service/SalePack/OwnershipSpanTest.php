<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\SalePack;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\SalePack\OwnershipSpan;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * "Owned since March 2021" and the distance covered (spec.md §7.19): from
 * the earliest reading on or after the purchase, only when it is within 31
 * days of it; never guessed.
 */
final class OwnershipSpanTest extends TestCase
{
    public function testAReadingOnThePurchaseDay(): void
    {
        $span = OwnershipSpan::of(self::vehicle('2021-03-14'), [
            self::reading(1, '40000', '2021-02-01T10:00:00Z'),
            self::reading(2, '41000', '2021-03-14T10:00:00Z'),
            self::reading(3, '78421', '2026-09-12T10:00:00Z'),
        ], self::london());

        self::assertNotNull($span);
        self::assertSame('2021-03-14', $span->from->format('Y-m-d'));
        self::assertFalse($span->isSold());
        self::assertSame('37421', $span->distanceKm, 'from the reading on the day, not the one before');
    }

    public function testTheFirstReadingByTheOwnersLocalDate(): void
    {
        // 23:30 UTC on 13 March is 00:30 on 14 March in Berlin: on the purchase day.
        $readings = [self::reading(1, '41000', '2021-03-13T23:30:00Z'), self::reading(2, '42000', '2021-06-01T10:00:00Z')];

        $berlin = new DateTimeZone('Europe/Berlin');
        self::assertSame('1000', OwnershipSpan::of(self::vehicle('2021-03-14'), $readings, $berlin)?->distanceKm);
        self::assertNull(
            OwnershipSpan::of(self::vehicle('2021-03-14'), $readings, self::london())?->distanceKm,
            'in London it was the day before; the next is months later',
        );
    }

    public function testAFirstReading45DaysLaterLeavesTheDistanceOut(): void
    {
        $span = OwnershipSpan::of(self::vehicle('2021-03-14'), [
            self::reading(1, '41000', '2021-04-28T10:00:00Z'),
            self::reading(2, '78421', '2026-09-12T10:00:00Z'),
        ], self::london());

        self::assertNotNull($span, 'the span is still known');
        self::assertNull($span->distanceKm);

        $within = OwnershipSpan::of(self::vehicle('2021-03-14'), [
            self::reading(1, '41000', '2021-04-14T10:00:00Z'),
            self::reading(2, '78421', '2026-09-12T10:00:00Z'),
        ], self::london());
        self::assertSame('37421', $within?->distanceKm, '31 days is still within');
    }

    public function testNoPurchaseDateMeansNoSpan(): void
    {
        $readings = [self::reading(1, '1000', '2021-03-14T10:00:00Z')];
        self::assertNull(OwnershipSpan::of(self::vehicle(null), $readings, self::london()));
    }

    public function testASoldVehicleCountsUpToTheSale(): void
    {
        $span = OwnershipSpan::of(self::vehicle('2021-03-14', '2026-05-20'), [
            self::reading(1, '41000', '2021-03-20T10:00:00Z'),
            self::reading(2, '76000', '2026-05-20T10:00:00Z'),
            self::reading(3, '76500', '2026-06-01T10:00:00Z'),
        ], self::london());

        self::assertNotNull($span);
        self::assertTrue($span->isSold());
        self::assertSame('2026-05-20', $span->until?->format('Y-m-d'));
        self::assertSame('35000', $span->distanceKm, 'the new owner\'s reading is not counted');
    }

    public function testOneReadingOrAFallGivesNoDistance(): void
    {
        $one = [self::reading(1, '41000', '2021-03-14T10:00:00Z')];
        self::assertNull(OwnershipSpan::of(self::vehicle('2021-03-14'), $one, self::london())?->distanceKm);
        self::assertNull(OwnershipSpan::of(self::vehicle('2021-03-14'), [
            self::reading(1, '41000', '2021-03-14T10:00:00Z'),
            self::reading(2, '400', '2022-03-14T10:00:00Z'),
        ], self::london())?->distanceKm);
    }

    private static function london(): DateTimeZone
    {
        return new DateTimeZone('Europe/London');
    }

    private static function vehicle(?string $purchased, ?string $sold = null): Vehicle
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');
        $data = new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            purchaseDate: $purchased === null ? null : LocalTime::parseDate($purchased),
            saleDate: $sold === null ? null : LocalTime::parseDate($sold),
        );

        return new Vehicle(1, 1, $data, VehicleStatus::Active, null, null, null, $now, $now);
    }

    private static function reading(int $id, string $km, string $at): OdometerReading
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');

        return new OdometerReading($id, 1, $km, new DateTimeImmutable($at), OdometerSource::Manual, null, null, $now, $now);
    }
}
