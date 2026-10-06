<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Reminder\CalendarDay;
use Logbook\Service\Reminder\CalendarMonth;
use Logbook\Service\Reminder\CalendarWeek;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderOverview;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reminders calendar's month (spec.md §7.6 *Calendar view*, §7.8
 * *Calendar*): weeks from the locale, week numbers, placement by due date,
 * the overdue strip and the month links.
 */
final class CalendarMonthTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function monthLengths(): iterable
    {
        yield 'February 2027 (28)' => ['2027-02-01', 28];
        yield 'February 2028 (29, leap year)' => ['2028-02-01', 29];
        yield 'April 2026 (30)' => ['2026-04-01', 30];
        yield 'October 2026 (31)' => ['2026-10-01', 31];
    }

    #[DataProvider('monthLengths')]
    public function testEveryDayOfTheMonthOnceInWholeWeeks(string $month, int $days): void
    {
        $calendar = self::month([], $month);

        self::assertCount($days, $calendar->days());
        $numbers = array_map(static fn (CalendarDay $d): int => (int) $d->date->format('j'), $calendar->days());
        self::assertSame(range(1, $days), $numbers);
        foreach ($calendar->weeks as $week) {
            self::assertCount(7, $week->days);
        }
        $first = $calendar->weeks[0]->days;
        $last = $calendar->weeks[count($calendar->weeks) - 1]->days;
        self::assertTrue(array_any($first, static fn (CalendarDay $d): bool => $d->inMonth), 'no empty leading week');
        self::assertTrue(array_any($last, static fn (CalendarDay $d): bool => $d->inMonth), 'no empty trailing week');
    }

    /**
     * Months of 2026 that start on each day of the week.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function monthStarts(): iterable
    {
        yield 'Monday (June)' => ['2026-06-01', 0];
        yield 'Tuesday (September)' => ['2026-09-01', 1];
        yield 'Wednesday (April)' => ['2026-04-01', 2];
        yield 'Thursday (January)' => ['2026-01-01', 3];
        yield 'Friday (May)' => ['2026-05-01', 4];
        yield 'Saturday (August)' => ['2026-08-01', 5];
        yield 'Sunday (February)' => ['2026-02-01', 6];
    }

    #[DataProvider('monthStarts')]
    public function testTheFirstFallsInItsWeekdayColumn(string $month, int $mondayColumn): void
    {
        $calendar = self::month([], $month, 'en_GB');

        $first = $calendar->weeks[0]->days;
        self::assertTrue($first[$mondayColumn]->inMonth);
        self::assertSame('01', $first[$mondayColumn]->date->format('d'));
        for ($i = 0; $i < $mondayColumn; $i++) {
            self::assertFalse($first[$i]->inMonth, 'the days before are the previous month\'s');
            self::assertSame([], $first[$i]->items);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weekStarts(): iterable
    {
        yield 'en_GB starts on Monday' => ['en_GB', 'Mon'];
        yield 'en_US starts on Sunday' => ['en_US', 'Sun'];
        yield 'ar_EG starts on Saturday' => ['ar_EG', 'Sat'];
        yield 'de_DE starts on Monday' => ['de_DE', 'Mon'];
    }

    #[DataProvider('weekStarts')]
    public function testTheWeekStartsOnTheLocalesFirstDay(string $locale, string $weekday): void
    {
        $calendar = self::month([], '2026-10-01', $locale);

        foreach ($calendar->weeks as $week) {
            self::assertSame($weekday, $week->days[0]->date->format('D'));
        }
        self::assertSame($weekday, $calendar->weekdays()[0]->format('D'));
    }

    public function testWeekNumbersFollowTheLocale(): void
    {
        $gb = array_map(static fn (CalendarWeek $w): int => $w->number, self::month([], '2026-12-01', 'en_GB')->weeks);
        $us = array_map(static fn (CalendarWeek $w): int => $w->number, self::month([], '2026-12-01', 'en_US')->weeks);
        $january = array_map(static fn (CalendarWeek $w): int => $w->number, self::month([], '2027-01-01', 'en_GB')->weeks);

        self::assertSame([49, 50, 51, 52, 53], $gb, 'ISO-style: 28 Dec 2026 starts week 53');
        self::assertSame(1, $us[count($us) - 1], 'US: the week of 1 January is week 1');
        self::assertSame(53, $january[0], 'the week of 1 January 2027 is still week 53 in en_GB');
    }

    public function testRemindersSitOnTheirDueDateUnshifted(): void
    {
        $car = self::vehicle();
        $calendar = self::month([
            self::entry(1, '2026-10-01', ReminderStatus::Upcoming, $car),
            self::entry(2, '2026-10-31', ReminderStatus::Upcoming, $car),
            self::entry(3, '2026-11-01', ReminderStatus::Upcoming, $car),
        ], '2026-10-01');

        self::assertSame([1], self::on($calendar, '2026-10-01'));
        self::assertSame([2], self::on($calendar, '2026-10-31'));
        // 1 November fills the last week but carries nothing.
        foreach ($calendar->weeks as $week) {
            foreach ($week->days as $day) {
                if (!$day->inMonth) {
                    self::assertSame([], $day->items);
                }
            }
        }
        self::assertSame(2, $calendar->openCount());
    }

    public function testTodayIsTheViewersDateAcrossTheClockChange(): void
    {
        $london = new DateTimeZone('Europe/London');
        // 00:30 UTC on 29 March 2026 is 00:30 GMT, and clocks go forward at 01:00 UTC.
        $clock = new MutableClock(new DateTimeImmutable('2026-03-29T00:30:00Z'));
        $today = LocalTime::today($clock, $london);
        self::assertSame(['2026-03-29'], self::today(self::empty($today)));

        // 23:30 UTC on 25 October 2026 is 23:30 GMT: still the 25th (BST ended at 01:00 UTC that day).
        $clock->set(new DateTimeImmutable('2026-10-25T23:30:00Z'));
        $today = LocalTime::today($clock, $london);
        self::assertSame(['2026-10-25'], self::today(self::empty($today)));

        // 23:30 UTC on 24 October 2026 is 00:30 BST on the 25th.
        $clock->set(new DateTimeImmutable('2026-10-24T23:30:00Z'));
        $today = LocalTime::today($clock, $london);
        self::assertSame(['2026-10-25'], self::today(self::empty($today)));
    }

    public function testOpenFirstByUrgencyThenClosedSoAClosedItemNeverHidesAnOpenOne(): void
    {
        $car = self::vehicle();
        $open = [
            self::entry(1, '2026-10-12', ReminderStatus::Overdue, $car),
            self::entry(2, '2026-10-12', ReminderStatus::Due, $car),
            self::entry(3, '2026-10-12', ReminderStatus::Upcoming, $car),
        ];
        $closed = [
            self::entry(4, '2026-10-12', ReminderStatus::Done, $car),
            self::entry(5, '2026-10-12', ReminderStatus::Dismissed, $car),
        ];
        $overview = new ReminderOverview($open, $closed, self::date('2026-10-06'));

        $with = CalendarMonth::of($overview, self::date('2026-10-01'), 'en_GB', true)->day('2026-10-12');
        self::assertNotNull($with);
        self::assertSame([1, 2, 3, 4, 5], self::ids($with->items));
        self::assertSame([1, 2, 3], self::ids($with->shown()));
        self::assertSame(2, $with->more());
        self::assertSame(ReminderStatus::Overdue, $with->urgent());
        self::assertSame(1, $with->count(ReminderStatus::Done));

        $without = CalendarMonth::of($overview, self::date('2026-10-01'), 'en_GB', false)->day('2026-10-12');
        self::assertNotNull($without);
        self::assertSame([1, 2, 3], self::ids($without->items), 'closed=0 hides done and dismissed');
        self::assertSame(0, $without->more());

        $onlyClosed = CalendarMonth::of(
            new ReminderOverview([], $closed, self::date('2026-10-06')),
            self::date('2026-10-01'),
            'en_GB',
            true,
        );
        self::assertNull($onlyClosed->day('2026-10-12')?->urgent(), 'no open item, no mark');
        self::assertSame(0, $onlyClosed->openCount());
    }

    public function testOverdueStripAndRemindersWithNoDate(): void
    {
        $car = self::vehicle();
        $calendar = self::month([
            self::entry(1, '2026-06-01', ReminderStatus::Overdue, $car),
            self::entry(2, '2025-01-10', ReminderStatus::Overdue, $car),
            self::entry(3, '2026-10-02', ReminderStatus::Overdue, $car),
            self::entry(4, '2026-09-30', ReminderStatus::Overdue, $car),
            self::entry(5, null, ReminderStatus::Upcoming, $car),
            self::entry(6, '2026-10-20', ReminderStatus::Due, $car),
        ], '2026-10-01');

        self::assertSame([2, 1, 4, 3], self::ids($calendar->overdue), 'every overdue reminder, most overdue first');
        self::assertSame([2, 1, 4], self::ids($calendar->overdueLinks()));
        self::assertSame([5], self::ids($calendar->undated));
        self::assertSame([3], self::on($calendar, '2026-10-02'), 'also on its own date');
        self::assertSame(2, $calendar->openCount(), 'this month: the 2nd and the 20th');
        self::assertSame(1, $calendar->overdueCount());
    }

    public function testTheMonthAndDayChosenFromTheQuery(): void
    {
        $today = self::date('2026-10-06');

        self::assertSame('2027-02-01', CalendarMonth::chosen('2027-02', null, $today)->format('Y-m-d'));
        self::assertSame('2027-02-01', CalendarMonth::chosen('2027-02', '2026-12-25', $today)->format('Y-m-d'), 'month wins');
        self::assertSame('2026-12-01', CalendarMonth::chosen(null, '2026-12-25', $today)->format('Y-m-d'), 'the day\'s month');
        foreach (['2026-13', '2026-1', 'nope', '0000-01', '2026-00', ['2026-01']] as $bad) {
            self::assertSame('2026-10-01', CalendarMonth::chosen($bad, null, $today)->format('Y-m-d'));
        }
        self::assertSame('2026-10-01', CalendarMonth::chosen(null, '2026-02-30', $today)->format('Y-m-d'));
        self::assertSame('UTC', CalendarMonth::chosen('2027-02', null, $today)->getTimezone()->getName());

        $calendar = self::month([], '2026-10-01');
        self::assertNull($calendar->day('2026-11-01'), 'outside the month');
        self::assertNull($calendar->day('2026-10-32'));
        self::assertNull($calendar->day(['2026-10-01']));
        self::assertSame('day-2026-10-05', $calendar->day('2026-10-05')?->anchor());
    }

    public function testPreviousAndNextStopFiveYearsAway(): void
    {
        $today = self::date('2026-10-06');
        $overview = new ReminderOverview([], [], $today);

        $here = CalendarMonth::of($overview, $today, 'en_GB', true);
        self::assertSame('2026-09', $here->previous());
        self::assertSame('2026-11', $here->next());
        self::assertSame('2026-10', $here->key());

        $edge = CalendarMonth::of($overview, self::date('2031-10-01'), 'en_GB', true);
        self::assertSame('2031-09', $edge->previous());
        self::assertNull($edge->next(), 'five years on');

        $past = CalendarMonth::of($overview, self::date('2021-10-01'), 'en_GB', true);
        self::assertNull($past->previous());
        self::assertSame('2021-11', $past->next());

        $far = CalendarMonth::of($overview, self::date('2040-01-01'), 'en_GB', true);
        self::assertNull($far->next());
        self::assertNull($far->previous(), 'still renders, but the links stop');
        self::assertCount(31, $far->days());
    }

    /**
     * @param list<ReminderEntry> $open
     */
    private static function month(array $open, string $month, string $locale = 'en_GB'): CalendarMonth
    {
        return CalendarMonth::of(new ReminderOverview($open, [], self::date('2026-10-06')), self::date($month), $locale, true);
    }

    /**
     * @return list<int> the ids on that day of the month
     */
    private static function on(CalendarMonth $calendar, string $date): array
    {
        $day = $calendar->day($date);
        self::assertNotNull($day, $date);

        return self::ids($day->items);
    }

    private static function empty(DateTimeImmutable $today): CalendarMonth
    {
        return CalendarMonth::of(new ReminderOverview([], [], $today), $today, 'en_GB', true);
    }

    private static function entry(int $id, ?string $due, ReminderStatus $status, Vehicle $vehicle): ReminderEntry
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new ReminderEntry(new Reminder(
            $id,
            $vehicle->id,
            ReminderSource::Manual,
            null,
            null,
            null,
            'Reminder ' . $id,
            null,
            $due === null ? null : self::date($due),
            null,
            7,
            $status,
            null,
            [],
            null,
            $status->isClosed() ? $now : null,
            $now,
            $now,
        ), $vehicle);
    }

    private static function vehicle(): Vehicle
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Vehicle(
            1,
            1,
            new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol),
            VehicleStatus::Active,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    private static function date(string $date): DateTimeImmutable
    {
        $parsed = LocalTime::parseDate($date);
        self::assertNotNull($parsed);

        return $parsed;
    }

    /**
     * @param list<ReminderEntry> $entries
     * @return list<int>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn (ReminderEntry $e): int => $e->reminder->id, $entries);
    }

    /**
     * @return list<string>
     */
    private static function today(CalendarMonth $calendar): array
    {
        return array_values(array_map(
            static fn (CalendarDay $d): string => $d->key(),
            array_filter($calendar->days(), static fn (CalendarDay $d): bool => $d->isToday),
        ));
    }
}
