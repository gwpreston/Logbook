<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Report periods (calendar dates, both inclusive) and the distance driven in
 * one, from the mileage log.
 */
final class ReportPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: int}>
     */
    public static function presets(): iterable
    {
        yield 'this month' => ['month', '2026-09-01', 1];
        yield 'last 3 months' => ['3m', '2026-07-01', 3];
        yield 'last 12 months' => ['12m', '2025-10-01', 12];
        yield 'this year' => ['ytd', '2026-01-01', 9];
        yield 'all time' => ['all', null, 1];
    }

    #[DataProvider('presets')]
    public function testPresetsEndTodayAndStartOnTheFirst(string $range, ?string $from, int $months): void
    {
        $period = ReportPeriod::fromQuery(['range' => $range], self::date('2026-09-27'));

        self::assertSame($range, $period->range->value);
        self::assertSame($from, $period->from?->format('Y-m-d'));
        self::assertSame('2026-09-27', $period->to->format('Y-m-d'));
        self::assertCount($months, $period->months());
    }

    public function testTheDefaultIsTheLastTwelveMonthsAndJunkFallsBackToIt(): void
    {
        $today = self::date('2026-01-15');

        foreach ([[], ['range' => 'nonsense'], ['range' => ['12m']]] as $query) {
            $period = ReportPeriod::fromQuery($query, $today);
            self::assertSame(ReportRange::TwelveMonths, $period->range);
            self::assertSame('2025-02-01', $period->from?->format('Y-m-d'), 'across the year boundary');
            self::assertSame([], $period->toQuery(), 'the default needs no parameters');
        }
    }

    public function testCustomRanges(): void
    {
        $today = self::date('2026-09-27');

        $period = ReportPeriod::fromQuery(['range' => 'custom', 'from' => '2026-02-10', 'to' => '2026-04-05'], $today);
        self::assertSame('2026-02-10', $period->from?->format('Y-m-d'));
        self::assertSame('2026-04-05', $period->to->format('Y-m-d'));
        $months = array_map(static fn (DateTimeImmutable $m): string => $m->format('Y-m'), $period->months());
        self::assertSame(['2026-02', '2026-03', '2026-04'], $months);
        self::assertTrue($period->contains(self::date('2026-02-10')), 'both ends are included');
        self::assertTrue($period->contains(self::date('2026-04-05')));
        self::assertFalse($period->contains(self::date('2026-04-06')));
        self::assertSame(['range' => 'custom', 'from' => '2026-02-10', 'to' => '2026-04-05'], $period->toQuery());

        $backwards = ReportPeriod::fromQuery(['range' => 'custom', 'from' => '2026-04-05', 'to' => '2026-02-10'], $today);
        self::assertSame('2026-02-10', $backwards->from?->format('Y-m-d'), 'typed backwards: turned around');

        $open = ReportPeriod::fromQuery(['range' => 'custom', 'from' => '2026-06-01'], $today);
        self::assertSame('2026-09-27', $open->to->format('Y-m-d'), 'no end: until today');

        $start = ReportPeriod::fromQuery(['range' => 'custom', 'to' => '2026-03-31'], $today);
        self::assertNull($start->from, 'no start: from the beginning');

        $invalid = ReportPeriod::fromQuery(['range' => 'custom', 'from' => '2026-02-30', 'to' => 'soon'], $today);
        self::assertSame(ReportRange::AllTime, $invalid->range, 'impossible dates are ignored');
    }

    public function testFiltersRoundTripThroughTheirQuery(): void
    {
        $today = self::date('2026-09-27');
        $query = ['range' => 'ytd', 'vehicle' => '3', 'include_archived' => '1'];

        $filter = ReportFilter::fromQuery($query, $today);
        self::assertSame(3, $filter->vehicleId);
        self::assertTrue($filter->includeArchived);
        self::assertSame($query, $filter->toQuery());

        $junk = ReportFilter::fromQuery(['vehicle' => '-1', 'include_archived' => 'yes'], $today);
        self::assertNull($junk->vehicleId);
        self::assertFalse($junk->includeArchived);
    }

    public function testDistanceIsMeasuredFromTheLastReadingBeforeThePeriod(): void
    {
        $zone = new DateTimeZone('Europe/London');
        $period = new ReportPeriod(ReportRange::Custom, self::date('2026-04-01'), self::date('2026-04-30'));
        $readings = [
            self::reading('1000.000', '2026-03-15T12:00:00Z'),
            self::reading('1200.000', '2026-03-31T22:30:00Z'), // 23:30 BST on 31 March: still before
            self::reading('1300.000', '2026-03-31T23:30:00Z'), // 00:30 BST on 1 April: inside
            self::reading('1650.500', '2026-04-30T12:00:00Z'),
            self::reading('2000.000', '2026-05-02T12:00:00Z'), // after: ignored
        ];

        self::assertSame('450.500', PeriodDistance::km($readings, $period, $zone, null));

        // Nothing before the period: from its first reading.
        self::assertSame('350.500', PeriodDistance::km(array_slice($readings, 2), $period, $zone, null));
        // One reading and nothing before: nothing measurable.
        self::assertNull(PeriodDistance::km([$readings[3]], $period, $zone, null));
        // No reading in the period at all.
        self::assertNull(PeriodDistance::km([$readings[0], $readings[4]], $period, $zone, null));
        // Going backwards overall (a replaced odometer) is not a distance.
        $backwards = [$readings[3], self::reading('10.000', '2026-04-30T13:00:00Z')];
        self::assertNull(PeriodDistance::km($backwards, $period, $zone, null));
    }

    public function testReadingsBeforeThePurchaseDateAreAnEarlierOwnersAndLeftOut(): void
    {
        $zone = new DateTimeZone('Europe/London');
        $period = new ReportPeriod(ReportRange::Custom, self::date('2026-01-01'), self::date('2026-12-31'));
        $readings = [
            self::reading('80000.000', '2025-11-20T12:00:00Z'), // an MOT under the last owner
            self::reading('82000.000', '2026-05-09T12:00:00Z'), // the day before the purchase
            self::reading('82100.000', '2026-05-10T09:00:00Z'), // the purchase day: the baseline
            self::reading('83100.000', '2026-09-01T12:00:00Z'),
        ];

        // Without a purchase date every reading counts, as before.
        self::assertSame('3100.000', PeriodDistance::km($readings, $period, $zone, null));
        // From the purchase day: 82100 to 83100.
        self::assertSame('1000.000', PeriodDistance::km($readings, $period, $zone, self::date('2026-05-10')));
        // Bought after the last reading: nothing was driven by this owner.
        self::assertNull(PeriodDistance::km($readings, $period, $zone, self::date('2026-09-02')));
    }

    public function testThePurchaseDayIsTheOwnersLocalDay(): void
    {
        $zone = new DateTimeZone('Europe/London');
        $readings = [
            self::reading('100.000', '2026-06-09T23:30:00Z'), // 00:30 BST on 10 June
            self::reading('200.000', '2026-06-20T12:00:00Z'),
        ];

        self::assertSame($readings, PeriodDistance::owned($readings, self::date('2026-06-10'), $zone));
        self::assertSame([$readings[1]], PeriodDistance::owned($readings, self::date('2026-06-11'), $zone));
    }

    public function testAMileageLogReachesBackToTheLaterOfTheStartAndThePurchase(): void
    {
        $zone = new DateTimeZone('UTC');
        $readings = [
            self::reading('80000.000', '2025-11-20T12:00:00Z'),
            self::reading('82100.000', '2026-05-10T09:00:00Z'),
        ];
        $purchased = self::date('2026-05-10');

        // A period starting before the purchase is measured from it.
        self::assertTrue(PeriodDistance::reachesBack($readings, self::date('2026-01-01'), $zone, $purchased));
        // The earlier owner's reading no longer covers a start on the purchase day...
        self::assertTrue(PeriodDistance::reachesBack($readings, $purchased, $zone, $purchased));
        // ...and without a reading on that day, nothing reaches back to it.
        self::assertFalse(PeriodDistance::reachesBack([$readings[0]], $purchased, $zone, $purchased));
        self::assertFalse(PeriodDistance::reachesBack([$readings[1]], self::date('2026-05-09'), $zone, null));
        self::assertFalse(PeriodDistance::reachesBack([], self::date('2026-05-09'), $zone, null));
    }

    /**
     * A month of Monthly spend links to Reports for that month (spec.md §7.8, #206).
     */
    public function testAMonthIsACustomPeriodFromItsFirstToItsLastDay(): void
    {
        foreach (
            [
            ['2026-02-14', '2026-02-01', '2026-02-28'],
            ['2024-02-01', '2024-02-01', '2024-02-29'],
            ['2026-12-31', '2026-12-01', '2026-12-31'],
            ['2026-03-01', '2026-03-01', '2026-03-31'],
            ] as [$day, $from, $to]
        ) {
            $month = ReportPeriod::month(self::date($day));
            self::assertSame(['range' => 'custom', 'from' => $from, 'to' => $to], $month->toQuery(), $day);
            self::assertCount(1, $month->months());
            // Reports reads the link back as the same month.
            $read = ReportPeriod::fromQuery($month->toQuery(), self::date('2026-09-27'));
            self::assertSame([$from, $to], [$read->from?->format('Y-m-d'), $read->to->format('Y-m-d')]);
        }
    }

    private static function date(string $date): DateTimeImmutable
    {
        $parsed = LocalTime::parseDate($date);
        self::assertNotNull($parsed);

        return $parsed;
    }

    private static function reading(string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading(1, 1, $km, $at, OdometerSource::Manual, null, null, $at, $at);
    }
}
