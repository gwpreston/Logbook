<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Service\Ai\Scan\PrintedDate;
use Logbook\Service\Ai\Scan\PrintedNumber;

/**
 * Finding dates, times and numbers in one line of a document's text, for
 * reading without AI (spec.md §7.27 *Reading without AI*, Phase 53.4).
 * Values are returned as printed, so the Mapper reads them as it reads a
 * model's; numbers are read here only to compare them, in the user's
 * locale, as the Mapper reads them.
 */
final class RuleText
{
    /** A printed number: grouped (`1.234,56`, `48,120`) or plain (`42.18`). Not part of a word (`AB12`). */
    public const string DIGITS = '(?:\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,3})?|\d+(?:[.,]\d+)?)';
 /** Past this many digits a number is not an amount, a volume, a price or a reading. */
    public const int MAX_DIGITS = 12;
    public const string NUMBER = '(?<![\p{L}\d.,])' . self::DIGITS . '(?!\d)';

    private const string MONTH = '\p{L}{3,10}\.?';
    private const array DATES = [
        '/(?<!\d)\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}(?!\d)/u',
        '/(?<![\d.,])\d{1,2}[\/.\-]\d{1,2}[\/.\-](?:\d{4}|\d{2})(?![\d.,])/u',
        '/(?<!\d)\d{1,2}(?:st|nd|rd|th)?\.?\s+' . self::MONTH . ',?\s+(?:\d{4}|\d{2})(?!\d)/iu',
        '/(?<!\p{L})' . self::MONTH . '\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}(?!\d)/iu',
    ];
    private const string TIME = '/(?<![\d.:])\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]m)?(?![\d:])/iu';

    /**
     * The first date printed on the line that reads as one, as printed.
     */
    public static function date(string $line, string $locale): ?string
    {
        foreach (self::dates($line, $locale) as [$text]) {
            return $text;
        }

        return null;
    }

    /**
     * Every date printed on the line that reads as one, in order, as
     * printed with its byte offset. Candidates are checked in order, and
     * one inside a date already found is skipped.
     *
     * @return \Generator<int, array{string, int}>
     */
    public static function dates(string $line, string $locale): \Generator
    {
        $candidates = [];
        foreach (self::DATES as $pattern) {
            if (preg_match_all($pattern, $line, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$text, $offset]) {
                    $candidates[] = [$text, $offset];
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        $end = 0;
        foreach ($candidates as [$text, $offset]) {
            if ($offset < $end || PrintedDate::read($text, $locale) === null) {
                continue;
            }
            $end = $offset + strlen($text);
            yield [$text, $offset];
        }
    }

    /**
     * The first time printed with a colon (`08:42`, `2:32 pm`), as printed.
     */
    public static function time(string $line): ?string
    {
        return preg_match(self::TIME, $line, $m) === 1 && PrintedDate::time($m[0]) !== null ? $m[0] : null;
    }

    /**
     * The line without its dates and times, each replaced by spaces, so
     * their figures are never taken for amounts.
     */
    public static function withoutDates(string $line): string
    {
        foreach ([...self::DATES, self::TIME] as $pattern) {
            $line = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => str_repeat(' ', mb_strlen($m[0])),
                $line,
            );
        }

        return $line;
    }

    /**
     * The numbers printed on a line (dates and times left out), as printed,
     * without one followed by `%`.
     *
     * @return list<string>
     */
    public static function numbers(string $line): array
    {
        if (preg_match_all('/' . self::NUMBER . '(?!\s?%)/u', self::withoutDates($line), $m) === false) {
            return [];
        }

        return $m[0];
    }

    /**
     * A printed number read in the user's locale, as the Mapper reads it.
     */
    public static function read(string $printed, string $locale): ?string
    {
        // A card's authorisation code or a barcode is never an amount, and too long to compare (merge review).
        if (preg_match_all('/\d/', $printed) > self::MAX_DIGITS) {
            return null;
        }

        return PrintedNumber::read($printed, $locale);
    }

    /**
     * The currency printed on a line: `£`, `€`, `$` or a code.
     */
    public static function currency(string $line): ?string
    {
        if (preg_match('/[£€$]|\b(?:GBP|EUR|USD|CHF|SEK|NOK|DKK|PLN|CZK|CAD|AUD|NZD|IEP)\b/u', $line, $m) !== 1) {
            return null;
        }

        return match ($m[0]) {
            '£' => 'GBP',
            '€' => 'EUR',
            '$' => 'USD',
            default => $m[0],
        };
    }

    /**
     * The line as evidence: trimmed and at most 120 characters.
     */
    public static function evidence(string $line): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $line)), 0, 120);
    }

    /**
     * A name at the start of a line (a vendor, a station): the text before
     * the first comma or wide gap, when it has three letters in a row, or
     * is a short name in capitals (`BP`).
     */
    public static function name(string $line): ?string
    {
        $parts = preg_split('/,|\s{3,}/u', trim($line));
        $name = trim($parts === false ? $line : $parts[0]);

        $named = preg_match('/\p{L}{3}/u', $name) === 1 || preg_match('/^\p{Lu}{2}(?:\s\p{Lu}{2,})*$/u', $name) === 1;

        return $named ? mb_substr($name, 0, 150) : null;
    }
}
