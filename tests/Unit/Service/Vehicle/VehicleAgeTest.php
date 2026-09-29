<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehicleAgeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, int, string}>
     */
    public static function ages(): iterable
    {
        yield 'exactly one year' => ['2025-03-14', '2026-03-14', 1, 0, 'vehicle.age.years'];
        yield 'a day short of a year' => ['2025-03-14', '2026-03-13', 0, 11, 'vehicle.age.months'];
        yield 'years and months' => ['2019-03-14', '2026-09-27', 7, 6, 'vehicle.age.years_months'];
        yield 'under a month' => ['2026-09-01', '2026-09-27', 0, 0, 'vehicle.age.under_month'];
        yield 'registered today' => ['2026-09-27', '2026-09-27', 0, 0, 'vehicle.age.under_month'];
        yield 'one month' => ['2026-08-27', '2026-09-27', 0, 1, 'vehicle.age.months'];
        yield '31 Jan: a month on the last day of February' => ['2026-01-31', '2026-02-28', 0, 1, 'vehicle.age.months'];
        yield '29 Feb: one on 28 Feb of a non-leap year' => ['2024-02-29', '2025-02-28', 1, 0, 'vehicle.age.years'];
        yield '29 Feb: still one on 1 Mar' => ['2024-02-29', '2025-03-01', 1, 0, 'vehicle.age.years'];
        yield '29 Feb: not yet one on 27 Feb' => ['2024-02-29', '2025-02-27', 0, 11, 'vehicle.age.months'];
    }

    #[DataProvider('ages')]
    public function testWholeYearsAndMonths(string $registered, string $today, int $years, int $months, string $key): void
    {
        $age = VehicleAge::between(self::date($registered), self::date($today));

        self::assertSame($years, $age->years);
        self::assertSame($months, $age->months);
        self::assertSame($key, $age->labelKey());
    }

    public function testTheAnniversaryFollowsTheOwnersTimeZone(): void
    {
        // 11:30 UTC on 13 March is already 14 March (00:30) in Auckland.
        $clock = new MutableClock(new DateTimeImmutable('2026-03-13T11:30:00Z'));
        $vehicle = self::vehicle('2025-03-14');

        $auckland = VehicleAge::of($vehicle, LocalTime::today($clock, new DateTimeZone('Pacific/Auckland')));
        $london = VehicleAge::of($vehicle, LocalTime::today($clock, new DateTimeZone('Europe/London')));

        self::assertNotNull($auckland);
        self::assertNotNull($london);
        self::assertSame([1, 0], [$auckland->years, $auckland->months]);
        self::assertSame([0, 11], [$london->years, $london->months]);
    }

    public function testNoAgeWithoutARegistrationDateOrWhenItIsAhead(): void
    {
        self::assertNull(VehicleAge::of(self::vehicle(null), self::date('2026-09-27')));
        self::assertNull(VehicleAge::of(self::vehicle('2026-09-28'), self::date('2026-09-27')));
    }

    public function testLifetimeAverageNeedsNinetyDaysAndAReading(): void
    {
        $reading = self::reading('12000');

        $registered = self::date('2026-07-01');
        self::assertNull(VehicleAge::between($registered, self::date('2026-09-28'))->averageKmPerYear($reading), '89 days');
        self::assertNotNull(VehicleAge::between($registered, self::date('2026-09-29'))->averageKmPerYear($reading), '90 days');
        self::assertNull(VehicleAge::between(self::date('2020-01-01'), self::date('2026-09-27'))->averageKmPerYear(null));
    }

    public function testLifetimeAverageIsTheReadingOverTheYears(): void
    {
        $age = VehicleAge::between(self::date('2022-01-01'), self::date('2026-01-01'));

        // 1,461 days (one leap year) = 4.0002 years of 365.2425 days.
        self::assertEqualsWithDelta(15000.0, $age->averageKmPerYear(self::reading('60000')), 1.0);
    }

    public function testTheLifetimeAverageIsMeasuredToTheReadingsDate(): void
    {
        // Three years old today, but the reading was taken 200 days ago.
        $vehicle = self::vehicle('2023-09-27');
        $reading = self::reading('30000', '2026-03-11T12:00:00Z');
        $zone = new DateTimeZone('Europe/London');

        $average = VehicleAge::lifetimeAverageKmPerYear($vehicle, $reading, $zone);
        // 30,000 km over the 896 days to 11 March 2026, not the 1,096 to today.
        self::assertEqualsWithDelta(30000 / (896 / 365.2425), $average, 0.01);
        self::assertGreaterThan(30000 / 3.0, $average, 'no longer understated');

        // Age itself is still to today.
        $age = VehicleAge::of($vehicle, self::date('2026-09-27'));
        self::assertNotNull($age);
        self::assertSame([3, 0], [$age->years, $age->months]);
    }

    public function testTheNinetyDayFloorIsMeasuredAtTheReading(): void
    {
        $vehicle = self::vehicle('2026-01-01');
        $zone = new DateTimeZone('Europe/London');

        // 89 days old at the reading, however old today.
        self::assertNull(VehicleAge::lifetimeAverageKmPerYear($vehicle, self::reading('900', '2026-03-31T12:00:00Z'), $zone));
        self::assertNotNull(VehicleAge::lifetimeAverageKmPerYear($vehicle, self::reading('900', '2026-04-01T12:00:00Z'), $zone));
    }

    public function testTheReadingsDateIsItsLocalDate(): void
    {
        $vehicle = self::vehicle('2026-01-01');
        // 23:30 UTC on 31 March is already 1 April in Berlin (90 days), still 31 March in New York (89).
        $reading = self::reading('900', '2026-03-31T23:30:00Z');

        self::assertNotNull(VehicleAge::lifetimeAverageKmPerYear($vehicle, $reading, new DateTimeZone('Europe/Berlin')));
        self::assertNull(VehicleAge::lifetimeAverageKmPerYear($vehicle, $reading, new DateTimeZone('America/New_York')));
    }

    public function testNoLifetimeAverageWithoutARegistrationOrAReadingOrBeforeRegistration(): void
    {
        $zone = new DateTimeZone('Europe/London');

        self::assertNull(VehicleAge::lifetimeAverageKmPerYear(self::vehicle(null), self::reading('1000'), $zone));
        self::assertNull(VehicleAge::lifetimeAverageKmPerYear(self::vehicle('2020-01-01'), null, $zone));
        self::assertNull(
            VehicleAge::lifetimeAverageKmPerYear(self::vehicle('2026-03-01'), self::reading('8', '2026-02-20T12:00:00Z'), $zone),
            'delivery mileage dated before registration',
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }

    private static function vehicle(?string $registered): Vehicle
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');
        $data = new VehicleData(
            VehicleType::Car,
            'Ford',
            'Focus',
            FuelType::Petrol,
            firstRegisteredOn: $registered === null ? null : self::date($registered),
        );

        return new Vehicle(1, 1, $data, VehicleStatus::Active, null, null, null, $now, $now);
    }

    private static function reading(string $km, string $recordedAt = '2026-09-27T10:00:00Z'): OdometerReading
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');

        return new OdometerReading(1, 1, $km, new DateTimeImmutable($recordedAt), OdometerSource::Manual, null, null, $now, $now);
    }
}
