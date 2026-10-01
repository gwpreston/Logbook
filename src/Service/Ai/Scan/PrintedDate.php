<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;

/**
 * A date as printed on a document, read in the user's locale order (UK:
 * day first; US: month first; ISO as written), spec.md §7.27 *Checking
 * values*. "04/05/2026" is 4 May in en_GB and 5 April in en_US, and either
 * way it is marked to check, with the other reading named.
 */
final readonly class PrintedDate
{
    private function __construct(
        public DateTimeImmutable $date,
        /** The other reading of a numeric date whose day and month are both 12 or under. */
        public ?DateTimeImmutable $alternative = null,
    ) {
    }

    public static function read(string $printed, string $locale): ?self
    {
        $text = mb_strtolower(trim($printed));
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', $text, $m) === 1) {
            $date = self::make((int) $m[1], (int) $m[2], (int) $m[3]);

            return $date === null ? null : new self($date);
        }

        if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})\b/', $text, $m) === 1) {
            $year = self::year($m[3]);
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            [$day, $month] = self::monthFirst($locale) ? [$b, $a] : [$a, $b];
            $date = self::make($year, $month, $day);
            if ($date === null) {
                // 25/12 in a month-first locale is still only one date.
                $date = self::make($year, $day, $month);

                return $date === null ? null : new self($date);
            }
            $alternative = $a <= 12 && $b <= 12 && $a !== $b ? self::make($year, $day, $month) : null;

            return new self($date, $alternative);
        }

        $months = self::months($locale);
        $names = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), array_keys($months)));
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\.?\s+(' . $names . ')\.?,?\s+(\d{4}|\d{2})\b/u', $text, $m) === 1) {
            $date = self::make(self::year($m[3]), $months[$m[2]], (int) $m[1]);

            return $date === null ? null : new self($date);
        }
        if (preg_match('/\b(' . $names . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/u', $text, $m) === 1) {
            $date = self::make((int) $m[3], $months[$m[1]], (int) $m[2]);

            return $date === null ? null : new self($date);
        }

        return null;
    }

    /**
     * "14:32" or "2:32 pm" on a receipt, as [hour, minute]; null when there is none.
     *
     * @return array{int, int}|null
     */
    public static function time(string $printed): ?array
    {
        if (preg_match('/\b(\d{1,2})[:.](\d{2})(?::\d{2})?\s*(am|pm)?\b/i', $printed, $m) !== 1) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $meridiem = strtolower($m[3] ?? '');
        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return $hour <= 23 && $minute <= 59 ? [$hour, $minute] : null;
    }

    private static function monthFirst(string $locale): bool
    {
        $pattern = (string) (new IntlDateFormatter($locale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE))->getPattern();
        $day = strpos($pattern, 'd');
        $month = strpos($pattern, 'M');

        return $day !== false && $month !== false && $month < $day;
    }

    /**
     * Month names and abbreviations in the user's language and in English → 1–12.
     *
     * @return array<string, int>
     */
    private static function months(string $locale): array
    {
        $names = [];
        foreach (array_unique([$locale, 'en']) as $language) {
            foreach (['MMMM', 'MMM', 'LLLL', 'LLL'] as $pattern) {
                $formatter = new IntlDateFormatter(
                    $language,
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    'UTC',
                    null,
                    $pattern,
                );
                for ($month = 1; $month <= 12; $month++) {
                    $mid = new DateTimeImmutable(sprintf('2026-%02d-15 12:00', $month), new DateTimeZone('UTC'));
                    $name = mb_strtolower(trim((string) $formatter->format($mid), '.'));
                    if ($name !== '') {
                        $names[$name] = $month;
                    }
                }
            }
        }
        $names['sept'] = 9;
        // Longest first, so "june" wins over "jun".
        uksort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }

    private static function year(string $digits): int
    {
        $year = (int) $digits;

        return strlen($digits) === 2 ? 2000 + $year : $year;
    }

    private static function make(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if ($year < 1900 || $year > 2200 || !checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone('UTC'));
    }
}
