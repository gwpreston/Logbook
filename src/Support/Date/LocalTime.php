<?php

declare(strict_types=1);

namespace Logbook\Support\Date;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The boundary between the user's wall clock and UTC storage (spec.md §8).
 *
 * Two kinds of value, never mixed up:
 *  - **instants** (when something happened): stored in UTC, shown in the
 *    user's time zone. Convert with toUtc() / fromUtc().
 *  - **calendar dates** (an expiry day, a purchase date): a day with no time
 *    and no zone. Represented as midnight UTC and never shifted, so
 *    "1 Jan" stays "1 Jan" for a user in New York or Tokyo.
 *
 * Local times that do not exist (the hour skipped when clocks go forward) are
 * moved forward by the gap; ambiguous ones (the repeated hour when clocks go
 * back) resolve to whichever of the two instants PHP picks, which depends on
 * the zone (the later one in London, the first in New York); firstOfRepeated()
 * gives the first. Both follow PHP. A time
 * written with its UTC offset ("2026-10-25T01:30:00+00:00", or "Z") is
 * exact, and the zone is not consulted (CSV import, K13); wallClock() says
 * whether a time without one is skipped or repeated.
 */
final class LocalTime
{
    /** The first and last year an entered, API or URL date may have (spec §8, K42). */
    public const int MIN_YEAR = 1900;
    public const int MAX_YEAR = 2100;

    private const array LOCAL_FORMATS = ['!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s'];

    /** A date and time followed by a UTC offset: `…T01:30:00+01:00`, `…T00:30Z`, `… 01:30 +0100`. */
    private const string WITH_OFFSET = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?\s*(Z|[+-]\d{2}:?\d{2})$/i';

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function isValidTimezone(string $identifier): bool
    {
        return in_array($identifier, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Parse a wall-clock date-time typed in $zone ("2026-03-29T09:15", as
     * submitted by `<input type="datetime-local">`) and return it in UTC.
     */
    public static function toUtc(string $localDateTime, DateTimeZone $zone): ?DateTimeImmutable
    {
        $input = trim($localDateTime);
        $datePart = substr($input, 0, 10);
        if (self::parseDate($datePart) === null) {
            return null;
        }

        $exact = self::withOffset($input);
        if ($exact !== false) {
            // The offset can carry the instant across the year limit the text itself is within.
            return $exact !== null && self::yearInRange((int) $exact->format('Y')) ? $exact : null;
        }

        foreach (self::LOCAL_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $input, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);

            // A time inside a DST gap is shifted forward but keeps its date;
            // anything that rolled into another day (25:00) was invalid.
            if ($parsed !== false && $clean && $parsed->format('Y-m-d') === $datePart) {
                return $parsed->setTimezone(self::utc());
            }
        }

        return null;
    }

    /**
     * Whether a wall-clock time without an offset is one instant in $zone,
     * falls in the hour skipped when the clocks go forward, or in the hour
     * repeated when they go back. A time with an offset, or one that doesn't
     * parse, is `Exists`: there is nothing to ask of the zone.
     */
    public static function wallClock(string $localDateTime, DateTimeZone $zone): WallClock
    {
        $pair = self::clockChange($localDateTime, $zone);
        if ($pair === null) {
            return WallClock::Exists;
        }
        [$seconds, $before, $after] = $pair;
        $valid = 0;
        foreach ([$before, $after] as $offset) {
            if ($zone->getOffset(new DateTimeImmutable('@' . ($seconds - $offset))) === $offset) {
                $valid++;
            }
        }

        return match ($valid) {
            0 => WallClock::Skipped,
            2 => WallClock::Repeated,
            default => WallClock::Exists,
        };
    }

    /**
     * The earlier of the two instants a repeated wall-clock time is (01:30
     * BST rather than 01:30 GMT); null when the time isn't repeated.
     */
    public static function firstOfRepeated(string $localDateTime, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (self::wallClock($localDateTime, $zone) !== WallClock::Repeated) {
            return null;
        }
        $pair = self::clockChange($localDateTime, $zone);
        assert($pair !== null);

        // The larger offset is the summer one, whose reading comes first.
        return (new DateTimeImmutable('@' . ($pair[0] - max($pair[1], $pair[2]))))->setTimezone(self::utc());
    }

    /**
     * The other instant of a repeated wall-clock time, given the first one
     * (01:30 GMT for 01:30 BST): the first moved by the change in offset.
     * Only meaningful for the first of a repeated time (`firstOfRepeated`).
     */
    public static function otherOfRepeated(DateTimeImmutable $first, DateTimeZone $zone): DateTimeImmutable
    {
        $summer = $zone->getOffset($first);
        $winter = $zone->getOffset($first->modify('+1 day'));

        return $first->modify(sprintf('%+d seconds', $summer - $winter));
    }

    /**
     * For a wall-clock time without an offset, on a day the zone's offset
     * changes: the reading as if it were UTC and the two offsets in force
     * a day either side. Null for any other time.
     *
     * @return array{int, int, int}|null
     */
    private static function clockChange(string $localDateTime, DateTimeZone $zone): ?array
    {
        $input = trim($localDateTime);
        if (preg_match(self::WITH_OFFSET, $input) === 1) {
            return null;
        }
        foreach (self::LOCAL_FORMATS as $format) {
            $naive = DateTimeImmutable::createFromFormat($format, $input, self::utc());
            if ($naive === false || DateTimeImmutable::getLastErrors() !== false) {
                continue;
            }
            $seconds = $naive->getTimestamp();
            $before = $zone->getOffset(new DateTimeImmutable('@' . ($seconds - 86400)));
            $after = $zone->getOffset(new DateTimeImmutable('@' . ($seconds + 86400)));

            return $before === $after ? null : [$seconds, $before, $after];
        }

        return null;
    }

    /**
     * A date and time with a UTC offset as a UTC instant; null when it isn't
     * one, false when the text has no offset.
     */
    private static function withOffset(string $input): DateTimeImmutable|false|null
    {
        if (preg_match(self::WITH_OFFSET, $input, $m) !== 1) {
            return false;
        }
        $offset = strtoupper($m[4]) === 'Z' ? '+00:00' : substr($m[4], 0, 3) . ':' . substr($m[4], -2);
        // Real offsets run from -12:00 to +14:00 in whole minutes.
        $minutes = (int) substr($offset, 1, 2) * 60 + (int) substr($offset, 4, 2);
        $limit = $offset[0] === '-' ? 12 * 60 : 14 * 60;
        if ((int) substr($offset, 4, 2) > 59 || $minutes > $limit) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:sP',
            sprintf('%sT%s:%s%s', $m[1], $m[2], $m[3] === '' ? '00' : $m[3], $offset),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->setTimezone(self::utc());
    }

    /**
     * An instant expressed in the user's time zone, for display or for
     * pre-filling a datetime-local input.
     */
    public static function fromUtc(DateTimeInterface $instant, DateTimeZone $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone($zone);
    }

    public static function yearInRange(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    /**
     * Parse a date that was entered, sent to the API or put in a URL
     * ("2026-09-27", as submitted by `<input type="date">`). Rejects impossible
     * dates such as 2026-02-30 and years outside 1900-2100.
     */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        $date = self::parseCalendarDate($value);

        return $date !== null && self::yearInRange((int) $date->format('Y')) ? $date : null;
    }

    /**
     * As parseDate() without the year range: for dates the app computes or
     * reads back (a stored date outside the range still reads, #558).
     */
    public static function parseCalendarDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::utc());

        return $date === false ? null : $date;
    }

    /**
     * The calendar date an instant falls on for someone in $zone, as a
     * calendar date (midnight UTC; comparable with parseDate()'s): a fill-up
     * at 00:30 BST on 1 April is on 1 April.
     */
    public static function dateOf(DateTimeInterface $instant, DateTimeZone $zone): DateTimeImmutable
    {
        $date = self::parseCalendarDate(self::fromUtc($instant, $zone)->format('Y-m-d'));
        assert($date instanceof DateTimeImmutable);

        return $date;
    }

    /**
     * A calendar date plus whole months, clamped to the end of a shorter
     * month: 31 Jan + 1 month is 28 (or 29) Feb, not 3 Mar as PHP's
     * "+1 month" would give.
     */
    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        // Months since year 0; dates are AD, so this stays non-negative.
        $total = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
        $firstOfMonth = $date->setDate(intdiv($total, 12), $total % 12 + 1, 1);
        $day = min((int) $date->format('j'), (int) $firstOfMonth->format('t'));

        return $firstOfMonth->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $day);
    }

    /**
     * Whole days from one calendar date to another (negative when $to is
     * earlier). Both are midnight UTC, so there is no DST to skew it.
     */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }

    /**
     * Today's calendar date for someone in $zone. Just after midnight in
     * Auckland it is already tomorrow there, while it is still today in UTC.
     */
    public static function today(ClockInterface $clock, DateTimeZone $zone): DateTimeImmutable
    {
        $local = $clock->now()->setTimezone($zone)->format('Y-m-d');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $local, self::utc());
        assert($date instanceof DateTimeImmutable);

        return $date;
    }
}
