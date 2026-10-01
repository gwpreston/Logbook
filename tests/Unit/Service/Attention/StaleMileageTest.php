<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Attention\StaleMileage;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * *Mileage not updated* (spec.md §7.24): only where projections need
 * readings, more than the threshold of days, counted in the owner's zone.
 */
final class StaleMileageTest extends TestCase
{
    public function testRaisedAt61DaysAndNotAt59OrAt60(): void
    {
        $today = self::day('2026-10-01');
        $zone = new DateTimeZone('UTC');

        self::assertTrue(StaleMileage::isStale(self::reading('2026-08-01T12:00:00Z'), true, $today, $zone, 60), '61 days');
        self::assertFalse(StaleMileage::isStale(self::reading('2026-08-02T12:00:00Z'), true, $today, $zone, 60), '60 days');
        self::assertFalse(StaleMileage::isStale(self::reading('2026-08-03T12:00:00Z'), true, $today, $zone, 60), '59 days');
    }

    public function testDaysAreCountedInTheOwnersTimeZone(): void
    {
        $today = self::day('2026-10-01');
        // 23:30 UTC on 1 Aug is already 2 Aug in Berlin: 60 days there, 61 in UTC.
        $reading = self::reading('2026-08-01T23:30:00Z');

        self::assertTrue(StaleMileage::isStale($reading, true, $today, new DateTimeZone('UTC'), 60));
        self::assertFalse(StaleMileage::isStale($reading, true, $today, new DateTimeZone('Europe/Berlin'), 60));
    }

    public function testNeverWithoutSomethingProjectedByDistance(): void
    {
        $today = self::day('2026-10-01');
        $zone = new DateTimeZone('UTC');

        self::assertFalse(StaleMileage::isStale(self::reading('2020-01-01T12:00:00Z'), false, $today, $zone, 60));
        self::assertFalse(StaleMileage::isStale(null, false, $today, $zone, 60));
        self::assertTrue(StaleMileage::isStale(null, true, $today, $zone, 60), 'no reading at all');
    }

    private static function reading(string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading(1, 1, '10000.000', $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
