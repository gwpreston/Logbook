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
        // A quantity, leading ("2 x") or trailing ("x2", "Qty 2"), stays in the name as "×2" (K18).
        $quantity = null;
        $text = $line;
        if (preg_match('/^(\d+)\s*[x×]\s+/iu', $text, $q) === 1) {
            $quantity = (int) $q[1];
            $text = substr($text, strlen($q[0]));
        }
        $price = null;
        // Groups of three after spaces (`1 234,56`) are one number (K18).
        $amount = '\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+[.,]\d{2}|\d[\d.,]*[.,]\d{2}';
        $trailing = '/\s((?:[£€$]\s?)?(?:' . $amount . ')|[£€$]\s?\d[\d.,]*)\s*(?:[£€$]|[A-Z]{3})?$/u';

        // A suffix after the price: a quantity, a VAT note or a one-letter VAT code (K18).
        // Only after a price: "Tyre 205 x 55" and "Fuse 10 A" keep theirs (K96, K97).
        $suffix = '/\s+(?:[x×]\s?(\d+)|qty:?\s?(\d+)|\(?(?:inc\.?|incl\.?|ex\.?)?\s?vat(?:\s?@?\s?\d+(?:[.,]\d+)?%)?\)?'
            . '|\(?\d{1,2}(?:[.,]\d+)?%\)?)$/iu';
        $stripped = $text;
        $count = null;
        while (preg_match($suffix, $stripped, $m) === 1) {
            $found = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
            if ($found !== '') {
                $count ??= (int) $found;
            }
            $stripped = substr($stripped, 0, -strlen($m[0]));
        }
        $stripped = (string) preg_replace('/(?<=\d)\s+[A-Z]$/u', '', $stripped);
        if ($stripped !== $text && preg_match($trailing, ' ' . $stripped) === 1) {
            $text = $stripped;
            $quantity ??= $count;
        }
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

        if ($quantity !== null && $quantity > 1) {
            $name .= ' ×' . $quantity;
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
