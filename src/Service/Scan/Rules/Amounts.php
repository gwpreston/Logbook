<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Support\Number\Decimal;

/**
 * The amounts a document prints, for a total (spec.md §7.27 *Reading
 * without AI*, #526). Each number above zero, but never one printed with a
 * unit or as a price (`42.18 L`, `107.2p/L`, `1,859 EUR/L`) or a percentage.
 * An amount is *labelled* when a total word comes before it on its row
 * (`TOTAL`, `AMOUNT`), *exact* when that word labels the total itself
 * (`TOTAL`, `AMOUNT DUE`), and neither when a change, discount, saving,
 * subtotal or tendered word stands between the label and it
 * (`TOTAL £45.26 (saving £2.00)`: 45.26 labelled, 2.00 not).
 */
final readonly class Amounts
{
    private const string CODES = 'GBP|EUR|USD|CHF';
    private const string UNIT_AFTER = 'litres|liters|litre|liter|ltrs|ltr|kwh|gallons|gallon|gals|gal|l|g'
        . '|pence|cents|cent|ct|p|c|ppl';

    public function __construct(private Keywords $keywords)
    {
    }

    /**
     * @param list<string> $rows
     * @return list<array{printed: string, read: string, row: string, labelled: bool, exact: bool, currency: bool}>
     */
    public function of(array $rows, string $locale): array
    {
        $found = [];
        $notUnit = '/^\s?(?:[£€$]|' . self::CODES . ')?\s?(?:(?:' . self::UNIT_AFTER . ')(?![\p{L}])|\/|per\b|%)/iu';
        foreach ($rows as $row) {
            $text = RuleText::withoutDates($row);
            if (preg_match_all('/' . RuleText::NUMBER . '/u', $text, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as [$printed, $offset]) {
                $after = substr($text, $offset + strlen($printed));
                if (preg_match($notUnit, $after) === 1) {
                    continue;
                }
                $read = RuleText::read($printed, $locale);
                if ($read === null || Decimal::compare($read, '0') <= 0) {
                    continue;
                }
                $before = substr($text, 0, $offset);
                $label = $this->lastStart('total', $locale, $before);
                $exact = $this->lastStart('invoice_total', $locale, $before);
                $blocked = $label !== null && $this->lastEnd('not_total', $locale, $before) > $label;
                $found[] = [
                    'printed' => $printed,
                    'read' => $read,
                    'row' => $row,
                    'labelled' => $label !== null && !$blocked,
                    'exact' => $exact !== null && !$blocked,
                    'currency' => preg_match('/(?:[£€$]|\b(?:' . self::CODES . '))\s?$/iu', $before) === 1
                        || preg_match('/^\s?(?:[£€$]|(?:' . self::CODES . ')\b)/iu', $after) === 1,
                ];
            }
        }

        return $found;
    }

    /**
     * The largest exact amount (a total no sum checks, #526), or null.
     *
     * @param list<array{printed: string, read: string, row: string, labelled: bool, exact: bool, currency: bool}> $amounts
     * @return array{printed: string, read: string, row: string, labelled: bool, exact: bool, currency: bool}|null
     */
    public static function largestExact(array $amounts): ?array
    {
        $best = null;
        foreach ($amounts as $amount) {
            if ($amount['exact'] && ($best === null || Decimal::compare($amount['read'], $best['read']) > 0)) {
                $best = $amount;
            }
        }

        return $best;
    }

    private function lastStart(string $list, string $locale, string $text): ?int
    {
        if (preg_match_all($this->keywords->pattern($list, $locale), $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        return $m[0][count($m[0]) - 1][1];
    }

    private function lastEnd(string $list, string $locale, string $text): int
    {
        if (preg_match_all($this->keywords->pattern($list, $locale), $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return -1;
        }
        $last = $m[0][count($m[0]) - 1];

        return $last[1] + strlen($last[0]);
    }
}
