<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Removes a registration document's reference number (spec.md §7.27): any
 * run of 11 digits with a space or none between them. In text that looks
 * like a V5C (looksLikeRegistration()) and in what a registration document
 * returns, also with one punctuation mark between them and, on OCR text,
 * OCR's look-alike letters (#500, K87, K93; narrowed to V5C-like text on
 * 2026-10-19, so `Diesel 9.21 1.459 13.44` or a date with seconds on any
 * other document is kept). Applied to PDF text and OCR text before it is
 * sent, and to every string read back from a registration document.
 */
final class Scrubber
{
    private const string ELEVEN_DIGITS = '/(?<![0-9])[0-9](?:[ \x{00A0}]?[0-9]){10}(?![0-9])/u';
    /** OCR's layout text may put a few spaces where the print had one (Phase 53.2). */
    private const string ELEVEN_DIGITS_OCR = '/(?<![0-9])[0-9](?:[ \x{00A0}]{0,3}[0-9]){10}(?![0-9])/u';
    /** V5C-like text: also `-` `/` `,` `.` `|` `:` between digits, with a space either side or none. */
    private const string GAP = '(?:[ \x{00A0}]|[ \x{00A0}]?[\-\/,.|:][ \x{00A0}]?)?';
    private const string WIDE = '/(?<![0-9])[0-9](?:' . self::GAP . '[0-9]){10}(?![0-9])/u';
    private const string GAP_OCR = '(?:[ \x{00A0}]{1,3}|[ \x{00A0}]{0,3}[\-\/,.|:][ \x{00A0}]{0,3})?';
    private const string WIDE_OCR = '/(?<![0-9])[0-9](?:' . self::GAP_OCR . '[0-9]){10}(?![0-9])/u';
    /** A V5C's markers, `VSC` too: OCR reads a 5 as an S (#510). */
    private const string MARKERS = '/\bV[5S]C\b|registration\s+certificate|\bDVLA\b|document\s+reference/iu';
    /** A strong sign on its own (#513): `V5C` as printed, not OCR's `VSC`, or `Registration Certificate`. */
    private const string STRONG = '/\bV5C\b|registration\s+certificate/iu';
    /**
     * A date (`14/10/2026`, `5.3.24`) or a time (`14:32`, `09:05:33`): never part of a reference, so
     * its digits can't join a group beside it into 11 (merge review of 53.8).
     */
    private const string DATE_OR_TIME = '/(?<![0-9])(?:[0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-](?:[0-9]{4}|[0-9]{2})'
        . '|[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)(?![0-9])/';
    /** OCR's look-alikes (#498), read as digits in a word that has a digit (#500). */
    private const array LOOK_ALIKES = [
        'O' => '0', 'o' => '0', 'l' => '1', 'I' => '1', 'i' => '1', 'S' => '5', 's' => '5', 'B' => '8', 'b' => '8',
    ];

    /**
     * $wide for V5C-like text: punctuation between the digits counts too.
     */
    public static function text(string $text, bool $wide = false): string
    {
        return self::removeRuns($wide ? self::WIDE : self::ELEVEN_DIGITS, $text);
    }

    /**
     * Whether text mentions any of a V5C's markers (spec.md §7.27, #510):
     * enough to keep the picture from an *Internet* connection (#513).
     */
    public static function mentionsRegistration(string $text): bool
    {
        return preg_match(self::MARKERS, $text) === 1;
    }

    /**
     * Whether text looks like a V5C, for the wide redaction (spec.md
     * §7.27, #513): two different markers (`V5C` and `VSC` are one), or
     * `V5C` or `Registration Certificate` on its own, so "Diagnostic VSC
     * warning light" or a passing "DVLA" keeps a document's figures.
     */
    public static function looksLikeRegistration(string $text): bool
    {
        if (preg_match(self::STRONG, $text) === 1) {
            return true;
        }
        preg_match_all(self::MARKERS, $text, $found);
        $kinds = array_map(
            static fn (string $m): string => strtolower((string) preg_replace('/\s+/u', ' ', str_replace('5', 's', $m))),
            $found[0],
        );

        return count(array_unique($kinds)) >= 2;
    }

    /**
     * OCR text before it is sent (Phase 53.2): as text(), allowing up to
     * three spaces (around a punctuation mark or not) between the digits,
     * since OCR's layout keeps gaps it saw.
     */
    public static function ocrText(string $text, bool $wide = false): string
    {
        return self::removeRuns($wide ? self::WIDE_OCR : self::ELEVEN_DIGITS_OCR, $text);
    }

    /**
     * $text without the runs $pattern finds, looked for with dates and
     * times masked, so `Issued 14/10/2026 12345 678901` loses the
     * reference, not `10/2026 12345` (merge review of 53.8).
     */
    private static function removeRuns(string $pattern, string $text): string
    {
        if (preg_match_all($pattern, self::masked($text), $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $text;
        }
        foreach (array_reverse($matches[0]) as [$run, $at]) {
            $text = substr($text, 0, $at) . substr($text, $at + strlen($run));
        }

        return $text;
    }

    /**
     * Whether a word is a date or a time (`14/10/2026`, `14:32`), which a
     * reference's digit groups pass over rather than join (merge review of 53.8).
     */
    public static function isDateOrTime(string $word): bool
    {
        $word = strtr(rtrim($word, ',;'), self::LOOK_ALIKES);

        return preg_match(self::DATE_OR_TIME, $word, $m) === 1 && $m[0] === $word;
    }

    /**
     * $text with each date and time's digits replaced by `x`, byte for
     * byte, so offsets found in it hold in $text.
     */
    private static function masked(string $text): string
    {
        return (string) preg_replace_callback(
            self::DATE_OR_TIME,
            static fn (array $m): string => (string) preg_replace('/[0-9]/', 'x', $m[0]),
            $text,
        );
    }

    /**
     * Which of a stretch of OCR words are part of an 11-digit run when the
     * words are read with single spaces between them (OCR's layout may put
     * any gap there). With $wide (V5C-like text), punctuation between groups
     * counts and, in a word with at least one digit, OCR's look-alikes count
     * as digits (`9O1`, `S678`); a word of letters only never does (#500).
     * Returns their indexes.
     *
     * @param list<string> $words
     * @return list<int>
     */
    public static function referenceWords(array $words, bool $wide = false): array
    {
        $joined = '';
        $starts = [];
        foreach ($words as $i => $word) {
            $starts[$i] = strlen($joined);
            $joined .= ($wide && preg_match('/[0-9]/', $word) === 1 ? strtr($word, self::LOOK_ALIKES) : $word) . ' ';
        }
        // A date or time beside the groups never joins them into 11 digits (merge review of 53.8).
        $joined = self::masked($joined);
        if (preg_match_all($wide ? self::WIDE : self::ELEVEN_DIGITS, $joined, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }
        $hit = [];
        foreach ($matches[0] as [$text, $at]) {
            $end = $at + strlen($text);
            foreach ($words as $i => $word) {
                if ($starts[$i] < $end && $starts[$i] + strlen($word) > $at) {
                    $hit[$i] = $i;
                }
            }
        }
        ksort($hit);

        return array_values($hit);
    }

    /**
     * How many digits a word of digit groups holds (look-alikes counted, a
     * punctuation mark allowed between them: `9O1`, `1234-5678`), or null
     * for any other word (`T0TAL`, `Ref`). For a reference wrapped over
     * rows (#500).
     */
    public static function digitsIn(string $word): ?int
    {
        if (preg_match('/[0-9]/', $word) !== 1) {
            return null;
        }
        $folded = strtr($word, self::LOOK_ALIKES);
        if (preg_match('/^[0-9]+(?:[\-\/,.|:][0-9]+)*[,.:;]?$/', $folded) !== 1) {
            return null;
        }

        return (int) preg_match_all('/[0-9]/', $folded);
    }

    /**
     * Every string in a decoded JSON value, its spaces folded first so a
     * run with wider gaps (`1234  5678  901`) is still caught.
     */
    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            // Only a registration document's answer comes here: the V5C rule applies.
            return self::text((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value), true);
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        return $value;
    }

    /**
     * As value(), for strings read from V5C-like OCR text by rules (Phase
     * 53.4): the words are read as one stretch whatever the gaps between
     * them, with OCR's look-alikes counted in a word that has a digit, so
     * `1234      S678 9O1` is removed too (merge review of 53.4).
     */
    public static function registrationValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text(self::withoutReferenceWords($value), true);
        }
        if (is_array($value)) {
            return array_map(self::registrationValue(...), $value);
        }

        return $value;
    }

    /**
     * A row of V5C-like text without the words of an 11-digit reference,
     * read as one stretch whatever the gaps between them, look-alikes
     * counted in a word with a digit (`1234      S678 9O1`). The other
     * words are kept, single-spaced.
     */
    public static function withoutReferenceWords(string $row): string
    {
        $words = preg_split('/[\s\x{00A0}\x{202F}]+/u', trim($row)) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
        $drop = array_flip(self::referenceWords($words, true));

        return implode(' ', array_filter($words, static fn (int $i): bool => !isset($drop[$i]), ARRAY_FILTER_USE_KEY));
    }

    public static function hasReference(string $text): bool
    {
        return preg_match(self::WIDE, $text) === 1;
    }
}
