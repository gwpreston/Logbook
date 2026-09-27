<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Calendar;

use DateTimeImmutable;
use Logbook\Support\Calendar\CalendarEvent;
use Logbook\Support\Calendar\ICalendar;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * RFC 5545 output: structure, escaping, folding, alarms.
 */
final class ICalendarTest extends TestCase
{
    public function testAFeedIsAWellFormedCalendar(): void
    {
        $ics = ICalendar::render('Logbook reminders', [
            new CalendarEvent(
                uid: 'reminder-1@garage.example',
                date: self::date('2026-10-09'),
                summary: 'Insurance — Golf',
                description: 'Policy POL-123',
                url: 'https://garage.example/reminders',
                alarmDaysBefore: 30,
            ),
            new CalendarEvent('reminder-2@garage.example', self::date('2026-12-31'), 'MOT — Bike'),
        ], new DateTimeImmutable('2026-09-27T10:00:00+01:00'));

        self::assertStringEndsWith("\r\n", $ics);
        self::assertStringNotContainsString("\r\n\r\n", $ics);
        self::assertSame(0, preg_match("/(?<!\r)\n/", $ics), 'every line ends in CRLF');

        $lines = ICalendarTest::unfold($ics);
        self::assertSame('BEGIN:VCALENDAR', $lines[0]);
        self::assertSame('END:VCALENDAR', end($lines));
        self::assertContains('VERSION:2.0', $lines);
        self::assertContains('PRODID:-//Logbook//Reminders//EN', $lines);
        self::assertSame(2, count(array_keys($lines, 'BEGIN:VEVENT', true)));
        self::assertSame(2, count(array_keys($lines, 'END:VEVENT', true)));
        self::assertContains('DTSTAMP:20260927T090000Z', $lines, 'DTSTAMP in UTC');
        self::assertContains('DTSTART;VALUE=DATE:20261009', $lines);
        self::assertContains('DTEND;VALUE=DATE:20261010', $lines, 'all day: ends the next day');
        self::assertContains('DTEND;VALUE=DATE:20270101', $lines, 'across the year end');
        self::assertContains('UID:reminder-1@garage.example', $lines);
        self::assertContains('URL:https://garage.example/reminders', $lines);
        self::assertContains('TRIGGER:-PT42660M', $lines, '09:00, 30 days before');
        self::assertSame(1, count(array_keys($lines, 'BEGIN:VALARM', true)), 'only the event with a lead time has an alarm');

        // Every property sits inside a component.
        $depth = 0;
        foreach ($lines as $line) {
            $depth += str_starts_with($line, 'BEGIN:') ? 1 : (str_starts_with($line, 'END:') ? -1 : 0);
            self::assertGreaterThanOrEqual(0, $depth);
        }
        self::assertSame(0, $depth, 'BEGIN/END balanced');
    }

    public function testTextValuesAreEscaped(): void
    {
        self::assertSame('Tyres\, brakes\; oil\\\\filter\nDone', ICalendar::text("Tyres, brakes; oil\\filter\r\nDone"));
    }

    public function testLongLinesFoldAtSeventyFiveOctetsWithoutSplittingCharacters(): void
    {
        $summary = 'SUMMARY:' . str_repeat('Überprüfung — ', 12);
        $folded = ICalendar::fold($summary);

        foreach (explode("\r\n", $folded) as $i => $line) {
            self::assertLessThanOrEqual(75, strlen($line), sprintf('line %d is too long', $i));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), sprintf('line %d splits a character', $i));
            if ($i > 0) {
                self::assertStringStartsWith(' ', $line);
            }
        }
        self::assertSame($summary, str_replace("\r\n ", '', $folded), 'unfolding restores the line');
        self::assertSame('SHORT:line', ICalendar::fold('SHORT:line'));
    }

    public function testAnAlarmOnTheDayItselfGoesOffThatMorning(): void
    {
        $event = new CalendarEvent('a@b', self::date('2026-10-01'), 'Today', alarmDaysBefore: 0);
        $ics = ICalendar::render('x', [$event], new DateTimeImmutable());

        self::assertContains('TRIGGER:PT540M', self::unfold($ics));
    }

    /**
     * @return list<string>
     */
    private static function unfold(string $ics): array
    {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
