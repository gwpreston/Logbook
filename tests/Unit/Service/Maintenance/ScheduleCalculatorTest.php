<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Maintenance;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Service\Maintenance\ScheduleCalculator;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * Next-due points from the last-done point and the intervals (spec.md §7.4).
 */
final class ScheduleCalculatorTest extends TestCase
{
    public function testDistanceOnly(): void
    {
        $next = ScheduleCalculator::nextDue(new DonePoint(self::date('2026-03-01'), '40000.000'), '10000.000', null);

        self::assertSame('50000.000', $next->km);
        self::assertNull($next->on, 'no months interval, no due date');
    }

    public function testMonthsOnly(): void
    {
        $next = ScheduleCalculator::nextDue(new DonePoint(self::date('2026-03-01'), '40000.000'), null, 12);

        self::assertSame('2027-03-01', $next->on?->format('Y-m-d'));
        self::assertNull($next->km, 'no distance interval, no due odometer');
    }

    public function testBothIntervalsGiveBothPoints(): void
    {
        $next = ScheduleCalculator::nextDue(new DonePoint(self::date('2026-01-31'), '12345.600'), '16093.440', 6);

        self::assertSame('2026-07-31', $next->on?->format('Y-m-d'));
        self::assertSame('28439.040', $next->km, 'exact decimal addition');
    }

    public function testMonthsAreClampedToTheEndOfShorterMonths(): void
    {
        $next = ScheduleCalculator::nextDue(new DonePoint(self::date('2026-08-31')), null, 6);

        self::assertSame('2027-02-28', $next->on?->format('Y-m-d'));
    }

    public function testEachHalfNeedsItsHalfOfTheLastDonePoint(): void
    {
        // Done on a known date, but the odometer was not noted.
        $dateOnly = ScheduleCalculator::nextDue(new DonePoint(self::date('2026-03-01')), '10000.000', 12);
        self::assertSame('2027-03-01', $dateOnly->on?->format('Y-m-d'));
        self::assertNull($dateOnly->km);

        $kmOnly = ScheduleCalculator::nextDue(new DonePoint(null, '40000.000'), '10000.000', 12);
        self::assertNull($kmOnly->on);
        self::assertSame('50000.000', $kmOnly->km);

        $never = ScheduleCalculator::nextDue(new DonePoint(), '10000.000', 12);
        self::assertFalse($never->isKnown());
    }

    public function testTheLatestEntryIsLastDoneElseTheBaseline(): void
    {
        $schedule = new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            '15000.000',
            12,
            self::date('2025-01-10'),
            '30000.000',
        );

        $baseline = ScheduleCalculator::lastDone($schedule, []);
        self::assertSame('2025-01-10', $baseline->on?->format('Y-m-d'));
        self::assertSame('30000.000', $baseline->km);

        $entries = [
            self::entry(2, '2026-02-01', '44000.000'),
            self::entry(1, '2025-12-01', '43000.000'),
        ];
        $last = ScheduleCalculator::lastDone($schedule, $entries);
        self::assertSame('2026-02-01', $last->on?->format('Y-m-d'), 'the latest by date, whatever the list order');
        self::assertSame('44000.000', $last->km);

        // Same day: the higher odometer is the later one.
        $sameDay = ScheduleCalculator::lastDone($schedule, [
            self::entry(3, '2026-02-01', '44100.000'),
            ...$entries,
        ]);
        self::assertSame('44100.000', $sameDay->km);
    }

    private static function entry(int $id, string $date, ?string $km): MaintenanceEntry
    {
        $data = new MaintenanceEntryData(self::date($date), MaintenanceCategory::Service, 'Service', '0.000', $km);

        return new MaintenanceEntry($id, 1, $data, new DateTimeImmutable(), new DateTimeImmutable());
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
