<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Dashboard\MileageSummary;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * The mileage widget (spec.md §7.8): calendar month and year in the owner's
 * time zone, fleet figures summed per vehicle, "—" (null) without history.
 */
final class MileageSummaryTest extends TestCase
{
    private static int $id = 0;

    public function testMonthAndYearFollowTheOwnersCalendar(): void
    {
        $london = new DateTimeZone('Europe/London');
        $readings = [
            self::reading('1000', '2026-01-01T00:30:00Z'),
            // 23:30 UTC on 31 Aug is 00:30 BST on 1 Sep: September's.
            self::reading('2000', '2026-08-31T23:30:00Z'),
            self::reading('2400', '2026-09-20T12:00:00Z'),
        ];

        $summary = MileageSummary::of([$readings], self::day('2026-09-27'), $london);

        // The last reading before September is the January one: 2,400 − 1,000.
        self::assertSame('1400', $summary->thisMonthKm);
        self::assertSame('1400', $summary->thisYearKm);

        $utc = MileageSummary::of([$readings], self::day('2026-09-27'), new DateTimeZone('UTC'));
        self::assertSame('400', $utc->thisMonthKm, 'in UTC the 2,000 reading is August\'s');
    }

    public function testAClockChangeDoesNotMoveAReadingToAnotherMonth(): void
    {
        $london = new DateTimeZone('Europe/London');
        // Clocks go back at 01:00 UTC on 25 Oct 2026; 31 Oct 23:30 UTC is still 31 Oct in GMT.
        $readings = [
            self::reading('500', '2026-09-30T22:30:00Z'),  // 23:30 BST, 30 Sep
            self::reading('800', '2026-10-31T23:30:00Z'),  // 23:30 GMT, 31 Oct
            self::reading('900', '2026-11-02T12:00:00Z'),
        ];

        $summary = MileageSummary::of([$readings], self::day('2026-11-05'), $london);

        self::assertSame('100', $summary->thisMonthKm);
        $october = $summary->months[10];
        self::assertSame('2026-10-01', $october->month->format('Y-m-d'));
        self::assertSame('300', $october->km);
    }

    public function testFleetFiguresAreSummedPerVehicle(): void
    {
        $utc = new DateTimeZone('UTC');
        $car = [self::reading('10000', '2026-08-31T12:00:00Z'), self::reading('10500', '2026-09-15T12:00:00Z')];
        $bike = [self::reading('20000', '2026-08-31T12:00:00Z'), self::reading('20100', '2026-09-15T12:00:00Z')];

        $summary = MileageSummary::of([$car, $bike], self::day('2026-09-27'), $utc);

        self::assertSame('600', $summary->thisMonthKm, 'never 20,100 − 10,000');
        self::assertNotNull($summary->monthlyAverageKm);
        self::assertCount(MileageSummary::CHART_MONTHS, $summary->months);
        self::assertSame('2025-10-01', $summary->months[0]->month->format('Y-m-d'));
        self::assertSame('600', $summary->months[11]->km);
    }

    public function testNoHistoryIsNotZero(): void
    {
        $summary = MileageSummary::of([[]], self::day('2026-09-27'), new DateTimeZone('UTC'));

        self::assertNull($summary->thisMonthKm);
        self::assertNull($summary->thisYearKm);
        self::assertNull($summary->monthlyAverageKm);
        self::assertFalse($summary->hasMonths());

        // History, but nothing driven this month: 0, not "—".
        $still = [self::reading('100', '2026-08-01T12:00:00Z')];
        self::assertSame('0', MileageSummary::of([$still], self::day('2026-09-27'), new DateTimeZone('UTC'))->thisMonthKm);
    }

    private static function reading(string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return new OdometerReading(++self::$id, 1, $km, $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
