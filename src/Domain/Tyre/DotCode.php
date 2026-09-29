<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The date code from a tyre's sidewall (spec.md §7.17): four digits, week
 * then two-digit year, so `2323` is week 23 of 2023. Codes from before 2000
 * had three digits and are not accepted. The manufacture date is the Monday
 * of that ISO week, a calendar date (midnight UTC, as LocalTime::parseDate
 * gives) computed without any time zone, so it never shifts.
 */
final readonly class DotCode
{
    private function __construct(
        /** The four digits as typed. */
        public string $code,
        /** Monday of the ISO week (midnight UTC). */
        public DateTimeImmutable $manufacturedOn,
    ) {
    }

    /**
     * Parse a code, or return the translation key of what is wrong with it.
     *
     * @param DateTimeImmutable $today the owner's calendar date (LocalTime::today())
     */
    public static function parse(string $value, DateTimeImmutable $today): self|string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}$/', $value) !== 1) {
            return 'tyre.error.dot_format';
        }

        $week = (int) substr($value, 0, 2);
        $year = 2000 + (int) substr($value, 2, 2);
        if ($week < 1 || $week > 53) {
            return 'tyre.error.dot_week';
        }

        $monday = self::monday($year, $week);
        // Week 53 exists only in a year that has one; otherwise it is week 1 of the next.
        if ((int) $monday->format('o') !== $year || (int) $monday->format('W') !== $week) {
            return 'tyre.error.dot_week';
        }
        if ($monday > $today) {
            return 'tyre.error.dot_future';
        }

        return new self($value, $monday);
    }

    /**
     * A stored code, trusted (it was checked when saved).
     */
    public static function fromStored(string $code, DateTimeImmutable $manufacturedOn): self
    {
        return new self($code, $manufacturedOn);
    }

    private static function monday(int $year, int $week): DateTimeImmutable
    {
        return (new DateTimeImmutable('@0'))->setTimezone(new DateTimeZone('UTC'))
            ->setISODate($year, $week, 1)
            ->setTime(0, 0);
    }
}
