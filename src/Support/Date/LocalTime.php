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
 * back) resolve to the later, standard-time instant. Both follow PHP.
 */
final class LocalTime
{
    private const array LOCAL_FORMATS = ['!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s'];

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
     * An instant expressed in the user's time zone, for display or for
     * pre-filling a datetime-local input.
     */
    public static function fromUtc(DateTimeInterface $instant, DateTimeZone $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone($zone);
    }

    /**
     * Parse a calendar date ("2026-09-27", as submitted by `<input type="date">`).
     * Rejects impossible dates such as 2026-02-30.
     */
    public static function parseDate(string $value): ?DateTimeImmutable
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
