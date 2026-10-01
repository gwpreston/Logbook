<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use IntlDateFormatter;

/**
 * Checks every figure in an answer against what Logbook gave the model
 * (spec.md §7.26 *Grounding check*). A figure is any number in the answer,
 * with or without separators, decimals, currency or unit; it is grounded
 * when some number in the tool results, the context or the question equals
 * it once rounded to the decimals the answer shows. Dates, times, years and
 * small counts (1–12) are never flagged.
 *
 * Numbers are read in the user's locale ("1,284.50" in English,
 * "1.284,50" in German) and, failing that, the other way round, so a raw
 * value copied from a tool result ("1284.50") still matches.
 */
final class GroundingCheck
{
    /**
     * A number: grouped thousands with an optional fraction, or plain digits
     * with an optional fraction. Not part of a longer word or number.
     */
    private const string NUMBER = '/(?<![\p{L}\d])[-−]?'
        . '(?:\d{1,3}(?:[,.\x{00A0}\x{202F}\']\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?)(?![\d])/u';

    private const array GROUP_SEPARATORS = ["\u{00A0}", "\u{202F}", "'"];

    /** @var array<string, list<string>> month names per locale */
    private array $months = [];

    /**
     * The figures in $answer that nothing in $sources supports, as they
     * appear in the answer, each once.
     *
     * @param list<string> $sources tool results (raw values and display strings), the context and the question
     * @return list<string>
     */
    public function ungrounded(string $answer, array $sources, string $locale): array
    {
        $allowed = [];
        foreach ($sources as $source) {
            foreach ($this->numbers($source, $locale, false) as [, $readings]) {
                foreach ($readings as $reading) {
                    $allowed[$reading] = true;
                }
            }
        }

        $flagged = [];
        foreach ($this->numbers($answer, $locale, true) as [$text, $readings]) {
            if (self::isTrivial($text) || $this->matches($readings, $allowed)) {
                continue;
            }
            $flagged[$text] = true;
        }

        return array_map(strval(...), array_keys($flagged));
    }

    /**
     * Each number in $text, as written and as canonical decimals ("1284.5")
     * in every reading that makes sense. Dates and times are left out.
     *
     * @return list<array{string, list<string>}>
     */
    public function numbers(string $text, string $locale, bool $skipDates): array
    {
        if ($skipDates) {
            $text = $this->withoutDates($text, $locale);
        }
        preg_match_all(self::NUMBER, $text, $matches);
        $found = [];
        foreach ($matches[0] as $token) {
            $readings = array_values(array_unique(array_filter([
                $this->read($token, self::decimalSeparator($locale)),
                $this->read($token, self::decimalSeparator($locale) === '.' ? ',' : '.'),
            ], static fn (?string $r): bool => $r !== null)));
            if ($readings !== []) {
                $found[] = [trim($token), $readings];
            }
        }

        return $found;
    }

    /**
     * @param list<string> $readings
     * @param array<string, true> $allowed
     */
    private function matches(array $readings, array $allowed): bool
    {
        foreach ($readings as $reading) {
            $places = self::places($reading);
            foreach (array_keys($allowed) as $value) {
                if (self::round((string) $value, $places) === $reading) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Years and small counts are never flagged: plain digits only, as
     * written ("2025", "3"). A grouped or decimal figure ("£1,000",
     * "1.950 €", "3.5") is always checked, whatever another reading of it
     * would be.
     */
    private static function isTrivial(string $token): bool
    {
        $digits = ltrim(str_replace('−', '-', $token), '-');
        if (!ctype_digit($digits)) {
            return false;
        }
        $value = (int) $digits;

        return $value <= 12 || ($value >= 1900 && $value <= 2100 && strlen($digits) === 4);
    }

    /**
     * "1.284,50" with "," as the decimal separator → "1284.5"; null when the
     * token can't be read that way (groups that aren't three digits).
     */
    /**
     * @param '.'|',' $decimal
     */
    private function read(string $token, string $decimal): ?string
    {
        $token = trim(str_replace('−', '-', $token));
        $negative = str_starts_with($token, '-');
        $token = ltrim($token, '-');
        $group = $decimal === '.' ? ',' : '.';
        $parts = explode($decimal, $token);
        if (count($parts) > 2) {
            return null;
        }
        $whole = str_replace([$group, ...self::GROUP_SEPARATORS], '', $parts[0], $groups);
        $grouped = '/^\d{1,3}(?:[' . preg_quote($group, '/') . "\u{00A0}\u{202F}']\\d{3})+$/u";
        if ($groups > 0 && preg_match($grouped, $parts[0]) !== 1) {
            return null;
        }
        if ($whole === '' || !ctype_digit($whole)) {
            return null;
        }
        $fraction = $parts[1] ?? '';
        if ($fraction !== '' && !ctype_digit($fraction)) {
            return null;
        }

        return self::canonical(($negative ? '-' : '') . $whole . ($fraction === '' ? '' : '.' . $fraction));
    }

    /**
     * Without trailing zeros in the fraction or leading zeros: "0012.50" → "12.5".
     */
    private static function canonical(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $out = ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);

        return $negative && $out !== '0' ? '-' . $out : $out;
    }

    private static function places(string $canonical): int
    {
        $dot = strpos($canonical, '.');

        return $dot === false ? 0 : strlen($canonical) - $dot - 1;
    }

    /**
     * A canonical decimal rounded half away from zero to $places, canonical again.
     */
    private static function round(string $value, int $places): string
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        if (strlen($fraction) <= $places) {
            return $value;
        }
        $kept = $whole . substr($fraction, 0, $places);
        $up = (int) $fraction[$places] >= 5;
        if ($up) {
            $kept = self::increment($kept);
        }
        $whole = substr($kept, 0, strlen($kept) - $places);
        $out = $whole . ($places > 0 ? '.' . substr($kept, -$places) : '');

        return self::canonical(($negative ? '-' : '') . $out);
    }

    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
            $digits[$i] = '0';
            $i--;
        }

        return '1' . $digits;
    }

    /**
     * @return '.'|','
     */
    private static function decimalSeparator(string $locale): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $symbol = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return $symbol === ',' ? ',' : '.';
    }

    /**
     * Blank out dates and times: ISO dates, numeric dates, a day beside a
     * month name ("1 Jan", "March 3"), and clock times.
     */
    private function withoutDates(string $text, string $locale): string
    {
        $text = (string) preg_replace(
            [
                '/\b\d{4}-\d{2}-\d{2}\b/u',
                '/\b\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}\b/u',
                '/\b\d{1,2}:\d{2}(?::\d{2})?\b/u',
            ],
            ' ',
            $text,
        );
        $names = implode('|', array_map(static fn (string $m): string => preg_quote($m, '/'), $this->monthNames($locale)));
        if ($names === '') {
            return $text;
        }

        return (string) preg_replace(
            [
                '/\b\d{1,2}(?:st|nd|rd|th)?\.?\s+(?:of\s+)?(?=(?:' . $names . ')\b)/iu',
                '/(?<=\b(?:' . $names . '))\.?\s+\d{1,2}(?:st|nd|rd|th)?\b/iu',
            ],
            ' ',
            $text,
        );
    }

    /**
     * Full and short month names in the locale and in English, longest first.
     *
     * @return list<string>
     */
    private function monthNames(string $locale): array
    {
        if (isset($this->months[$locale])) {
            return $this->months[$locale];
        }
        $names = [];
        foreach (array_unique([$locale, 'en']) as $language) {
            foreach (['MMMM', 'MMM'] as $pattern) {
                $formatter = new IntlDateFormatter(
                    $language,
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    'UTC',
                    null,
                    $pattern,
                );
                for ($month = 1; $month <= 12; $month++) {
                    $name = $formatter->format(new \DateTimeImmutable(sprintf('2026-%02d-15T12:00:00Z', $month)));
                    if (is_string($name) && $name !== '') {
                        $names[] = rtrim($name, '.');
                    }
                }
            }
        }
        $names = array_values(array_unique($names));
        usort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this->months[$locale] = $names;
    }
}
