<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Removes a registration document's reference number (spec.md §7.27):
 * any run of 11 digits, with a space, or one punctuation mark with a space
 * either side or none, between them (decided 2026-10-18, #500, K93).
 * Applied to PDF text before it is sent, to OCR text (with look-alike
 * letters and short gaps, referenceWords()), and to every string read back
 * from a registration document before it is shown or stored.
 */
final class Scrubber
{
    /** Between two digits of a run: a space, or `-` `/` `,` `.` `|` `:` with a space either side or none. */
    private const string GAP = '(?:[ \x{00A0}]|[ \x{00A0}]?[\-\/,.|:][ \x{00A0}]?)?';
    private const string ELEVEN_DIGITS = '/(?<![0-9])[0-9](?:' . self::GAP . '[0-9]){10}(?![0-9])/u';
    /** OCR's layout text may put a few spaces where the print had one (Phase 53.2). */
    private const string GAP_OCR = '(?:[ \x{00A0}]{1,3}|[ \x{00A0}]{0,3}[\-\/,.|:][ \x{00A0}]{0,3})?';
    private const string ELEVEN_DIGITS_OCR = '/(?<![0-9])[0-9](?:' . self::GAP_OCR . '[0-9]){10}(?![0-9])/u';
    /** OCR's look-alikes (#498), read as digits in a word that has a digit (#500). */
    private const array LOOK_ALIKES = [
        'O' => '0', 'o' => '0', 'l' => '1', 'I' => '1', 'i' => '1', 'S' => '5', 's' => '5', 'B' => '8', 'b' => '8',
    ];

    public static function text(string $text): string
    {
        return (string) preg_replace(self::ELEVEN_DIGITS, '', $text);
    }

    /**
     * OCR text before it is sent (Phase 53.2): as text(), allowing up to
     * three spaces (around a punctuation mark or not) between the digits,
     * since OCR's layout keeps gaps it saw.
     */
    public static function ocrText(string $text): string
    {
        return (string) preg_replace(self::ELEVEN_DIGITS_OCR, '', $text);
    }

    /**
     * Which of a stretch of OCR words are part of an 11-digit run when the
     * words are read with single spaces between them (OCR's layout may put
     * any gap there). In a word with at least one digit, OCR's look-alikes
     * count as digits (`9O1`, `S678`); a word of letters only never does
     * (#500). Returns their indexes.
     *
     * @param list<string> $words
     * @return list<int>
     */
    public static function referenceWords(array $words): array
    {
        $joined = '';
        $starts = [];
        foreach ($words as $i => $word) {
            $starts[$i] = strlen($joined);
            $joined .= (preg_match('/[0-9]/', $word) === 1 ? strtr($word, self::LOOK_ALIKES) : $word) . ' ';
        }
        if (preg_match_all(self::ELEVEN_DIGITS, $joined, $matches, PREG_OFFSET_CAPTURE) === 0) {
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
            return self::text((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value));
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        return $value;
    }

    public static function hasReference(string $text): bool
    {
        return preg_match(self::ELEVEN_DIGITS, $text) === 1;
    }
}
