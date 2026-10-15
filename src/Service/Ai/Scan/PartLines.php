<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Support\Number\Decimal;

/**
 * A service invoice's parts lines read as part rows (spec.md §7.27 *Parts
 * lines*, §7.39 *Scanning*, #440): a name, a part number when a token looks
 * like one, and a trailing price. A guess the owner reads and edits before
 * ticking: nothing is created from it without *Save*, and a warranty is
 * never read from a line.
 */
final class PartLines
{
    public const int MAX = 20;
    private const int NAME_MAX = 120;

    /**
     * @param list<string> $lines
     * @return list<array{name: string, part_number: ?string, price: ?string, line: string}>
     */
    public static function parse(array $lines, string $locale): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }
            $rows[] = self::row($line, $locale);
            if (count($rows) === self::MAX) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array{name: string, part_number: ?string, price: ?string, line: string}
     */
    private static function row(string $line, string $locale): array
    {
        $text = (string) preg_replace('/^\d+\s*[x×]\s*/iu', '', $line);

        $price = null;
        $trailing = '/\s((?:[£€$]\s?)?\d[\d.,]*[.,]\d{2}|[£€$]\s?\d[\d.,]*)\s*(?:[£€$]|[A-Z]{3})?$/u';
        if (preg_match($trailing, ' ' . $text, $m) === 1) {
            $read = PrintedNumber::read($m[1], $locale);
            if ($read !== null && Decimal::compare($read, '0') >= 0) {
                $price = Decimal::trim($read);
                $text = trim((string) preg_replace($trailing, '', ' ' . $text));
            }
        }

        $number = null;
        $words = [];
        foreach (explode(' ', $text) as $word) {
            if ($number === null && self::looksLikeAPartNumber(trim($word, '(),;:'))) {
                $number = trim($word, '(),;:');

                continue;
            }
            $words[] = $word;
        }
        $name = trim(implode(' ', $words), " \t-–—:,;");
        if ($name === '') {
            $name = trim($text, " \t-–—:,;") ?: $line;
            $number = null;
        }

        return [
            'name' => mb_substr($name, 0, self::NAME_MAX),
            'part_number' => $number === null ? null : mb_substr($number, 0, 80),
            'price' => $price,
            'line' => $line,
        ];
    }

    /**
     * A token with digits and letters or dashes ("OC593", "1K0-129-620"), but not an oil grade
     * ("5W-30") or a size ("205/55R16").
     */
    private static function looksLikeAPartNumber(string $token): bool
    {
        if (strlen($token) < 4 || strlen($token) > 40 || preg_match('/\d/', $token) !== 1) {
            return false;
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9\-\/.]*$/i', $token) !== 1 || preg_match('/^[\d.,]+$/', $token) === 1) {
            return false;
        }
        if (preg_match('/^\d{1,2}W-?\d{2}$/i', $token) === 1 || preg_match('/^\d{3}\/\d{2}/', $token) === 1) {
            return false;
        }

        return preg_match('/[A-Z]/i', $token) === 1 || str_contains($token, '-');
    }
}
