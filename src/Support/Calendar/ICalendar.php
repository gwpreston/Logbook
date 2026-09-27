<?php

declare(strict_types=1);

namespace Logbook\Support\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Writes an iCalendar (RFC 5545) feed of all-day events: CRLF line endings,
 * TEXT values escaped, lines folded at 75 octets without splitting a UTF-8
 * character.
 */
final class ICalendar
{
    private const int LINE_OCTETS = 75;
    /** Alarms go off at this minute of the day (09:00). */
    private const int ALARM_MINUTE_OF_DAY = 9 * 60;

    /**
     * @param list<CalendarEvent> $events
     * @param DateTimeImmutable $stamp when the feed was generated (DTSTAMP)
     */
    public static function render(string $name, array $events, DateTimeImmutable $stamp): string
    {
        $dtstamp = $stamp->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Logbook//Reminders//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($name),
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::text($event->uid);
            $lines[] = 'DTSTAMP:' . $dtstamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . $event->date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $event->date->modify('+1 day')->format('Ymd');
            $lines[] = 'SUMMARY:' . self::text($event->summary);
            if ($event->description !== '') {
                $lines[] = 'DESCRIPTION:' . self::text($event->description);
            }
            if ($event->url !== null) {
                $lines[] = 'URL:' . $event->url;
            }
            $lines[] = 'TRANSP:TRANSPARENT';
            if ($event->alarmDaysBefore !== null) {
                $lines[] = 'BEGIN:VALARM';
                $lines[] = 'ACTION:DISPLAY';
                $lines[] = 'DESCRIPTION:' . self::text($event->summary);
                $lines[] = 'TRIGGER:' . self::trigger($event->alarmDaysBefore);
                $lines[] = 'END:VALARM';
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    /**
     * Escape a TEXT value (RFC 5545 §3.3.11).
     */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return strtr($value, ['\\' => '\\\\', ';' => '\;', ',' => '\,', "\n" => '\n']);
    }

    /**
     * Fold a content line (RFC 5545 §3.1): at most 75 octets per line,
     * continuation lines start with a space.
     */
    public static function fold(string $line): string
    {
        $out = '';
        $current = '';
        $limit = self::LINE_OCTETS;
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current . "\r\n ";
                $current = '';
                $limit = self::LINE_OCTETS - 1;
            }
            $current .= $char;
        }

        return $out . $current;
    }

    /**
     * Alarm offset from the event's start (midnight): 09:00 on the day that
     * many days before.
     */
    private static function trigger(int $daysBefore): string
    {
        $minutes = self::ALARM_MINUTE_OF_DAY - $daysBefore * 24 * 60;

        return ($minutes < 0 ? '-' : '') . 'PT' . abs($minutes) . 'M';
    }
}
