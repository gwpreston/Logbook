<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Date;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Time zones and DST are the classic source of "wrong totals" bugs
 * (CLAUDE.md §8), so the local ↔ UTC boundary is tested explicitly.
 */
final class LocalTimeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function conversions(): iterable
    {
        yield 'London winter (GMT)' => ['2026-01-15T12:00', 'Europe/London', '2026-01-15T12:00:00+00:00'];
        yield 'London summer (BST)' => ['2026-07-01T12:00', 'Europe/London', '2026-07-01T11:00:00+00:00'];
        yield 'New York summer' => ['2026-07-01T20:30', 'America/New_York', '2026-07-02T00:30:00+00:00'];
        yield 'Kolkata half-hour offset' => ['2026-03-01T05:15', 'Asia/Kolkata', '2026-02-28T23:45:00+00:00'];
        yield 'Chatham 45-minute offset' => ['2026-06-01T00:00', 'Pacific/Chatham', '2026-05-31T11:15:00+00:00'];
        yield 'with seconds' => ['2026-01-15 12:00:30', 'UTC', '2026-01-15T12:00:30+00:00'];
        // Clocks go forward at 01:00 GMT: 01:30 does not exist and moves on by the gap.
        yield 'London DST gap' => ['2026-03-29T01:30', 'Europe/London', '2026-03-29T01:30:00+00:00'];
        // Clocks go back at 02:00 BST: 01:30 happens twice; the later (GMT) one is used.
        yield 'London DST overlap' => ['2026-10-25T01:30', 'Europe/London', '2026-10-25T01:30:00+00:00'];
    }

    #[DataProvider('conversions')]
    public function testLocalToUtc(string $local, string $zone, string $expectedUtc): void
    {
        $utc = LocalTime::toUtc($local, new DateTimeZone($zone));

        self::assertNotNull($utc);
        self::assertSame('UTC', $utc->getTimezone()->getName());
        self::assertSame($expectedUtc, $utc->format('c'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zones(): iterable
    {
        foreach (['UTC', 'Europe/London', 'America/New_York', 'Australia/Sydney', 'Asia/Kolkata', 'Pacific/Chatham'] as $zone) {
            yield $zone => [$zone];
        }
    }

    /**
     * Every quarter hour across both 2026 DST transitions, except the
     * non-existent and repeated hours, survives local → UTC → local.
     */
    #[DataProvider('zones')]
    public function testRoundTripAcrossTheYear(string $zoneName): void
    {
        $zone = new DateTimeZone($zoneName);
        $transitions = $zone->getTransitions((int) strtotime('2026-01-01'), (int) strtotime('2027-01-01'));

        foreach (array_slice($transitions, 1) as $transition) {
            $instant = new DateTimeImmutable('@' . $transition['ts']);
            for ($minutes = -180; $minutes <= 180; $minutes += 15) {
                $local = LocalTime::fromUtc($instant->modify(sprintf('%+d minutes', $minutes)), $zone);
                $typed = $local->format('Y-m-d\TH:i');
                $utc = LocalTime::toUtc($typed, $zone);
                self::assertNotNull($utc);

                $again = LocalTime::fromUtc($utc, $zone)->format('Y-m-d\TH:i');
                // In the repeated hour two instants share one wall time: the
                // wall time must still survive, even if the instant is the other one.
                self::assertSame($typed, $again, sprintf('%s in %s', $typed, $zoneName));
            }
        }

        $summer = LocalTime::toUtc('2026-07-15T09:45', $zone);
        self::assertNotNull($summer);
        self::assertSame('2026-07-15T09:45', LocalTime::fromUtc($summer, $zone)->format('Y-m-d\TH:i'));
    }

    public function testInvalidLocalTimesAreRejected(): void
    {
        $zone = new DateTimeZone('Europe/London');

        self::assertNull(LocalTime::toUtc('2026-02-30T10:00', $zone));
        self::assertNull(LocalTime::toUtc('2026-01-01T25:00', $zone));
        self::assertNull(LocalTime::toUtc('yesterday', $zone));
        self::assertNull(LocalTime::toUtc('', $zone));
    }

    public function testCalendarDatesAreStrictAndZoneless(): void
    {
        $date = LocalTime::parseDate('2024-02-29');

        self::assertNotNull($date);
        self::assertSame('2024-02-29T00:00:00+00:00', $date->format('c'));
        self::assertNull(LocalTime::parseDate('2026-02-29'));
        self::assertNull(LocalTime::parseDate('2026-13-01'));
        self::assertNull(LocalTime::parseDate('26-1-1'));
        self::assertNull(LocalTime::parseDate('2026-01-01T00:00'));
    }

    public function testTodayDependsOnTheUsersZone(): void
    {
        // 13:00 UTC on 30 June is already 1 July in Auckland (UTC+12).
        $clock = new MutableClock(new DateTimeImmutable('2026-06-30T13:00:00Z'));

        self::assertSame('2026-06-30', LocalTime::today($clock, new DateTimeZone('UTC'))->format('Y-m-d'));
        self::assertSame('2026-07-01', LocalTime::today($clock, new DateTimeZone('Pacific/Auckland'))->format('Y-m-d'));
        self::assertSame('2026-06-30', LocalTime::today($clock, new DateTimeZone('America/Los_Angeles'))->format('Y-m-d'));
        self::assertSame('00:00:00 UTC', LocalTime::today($clock, new DateTimeZone('Pacific/Auckland'))->format('H:i:s T'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function monthAdditions(): iterable
    {
        yield 'plain' => ['2026-03-15', 12, '2027-03-15'];
        yield 'end of January to February' => ['2026-01-31', 1, '2026-02-28'];
        yield 'into a leap February' => ['2027-01-31', 13, '2028-02-29'];
        yield 'leap day plus a year' => ['2024-02-29', 12, '2025-02-28'];
        yield 'across the year end' => ['2026-11-30', 3, '2027-02-28'];
        yield '31st to a 30-day month' => ['2026-08-31', 1, '2026-09-30'];
        yield 'many years' => ['2026-05-10', 120, '2036-05-10'];
        yield 'zero' => ['2026-05-10', 0, '2026-05-10'];
    }

    #[DataProvider('monthAdditions')]
    public function testAddMonthsClampsToTheEndOfShorterMonths(string $from, int $months, string $expected): void
    {
        $date = LocalTime::parseDate($from);
        self::assertNotNull($date);

        $result = LocalTime::addMonths($date, $months);

        self::assertSame($expected, $result->format('Y-m-d'));
        self::assertSame('00:00:00 UTC', $result->format('H:i:s T'), 'still a calendar date');
    }

    public function testDaysBetweenCalendarDates(): void
    {
        $a = LocalTime::parseDate('2026-03-01');
        $b = LocalTime::parseDate('2026-03-31');
        self::assertNotNull($a);
        self::assertNotNull($b);

        self::assertSame(30, LocalTime::daysBetween($a, $b));
        self::assertSame(-30, LocalTime::daysBetween($b, $a));
        self::assertSame(0, LocalTime::daysBetween($a, $a));
    }

    public function testTimezoneValidation(): void
    {
        self::assertTrue(LocalTime::isValidTimezone('Europe/London'));
        self::assertFalse(LocalTime::isValidTimezone('Europe/Atlantis'));
        self::assertFalse(LocalTime::isValidTimezone('+01:00'));
    }
}
