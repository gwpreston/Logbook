<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Support\Number\Decimal;

/**
 * The amounts a document prints (spec.md §7.27 *Reading without AI*,
 * #526). Never a number printed with a unit or as a price (`42.18 L`,
 * `107.2p/L`, `1,859 EUR/L`), a percentage, or one too long to be an
 * amount. Each row is scanned once per keyword list, so a long row costs
 * no more than its length (merge review of 53.4).
 */
final readonly class Amounts
{
    private const string CODES = 'GBP|EUR|USD|CHF';
    private const string UNIT_AFTER = 'litres|liters|litre|liter|ltrs|ltr|kwh|gallons|gallon|gals|gal|l|g'
        . '|pence|cents|cent|ct|p|c|ppl';
    /**
     * What may stand between a grand-total label and its amount: `:`, `=`,
     * `(inc VAT)`, a currency. Never a dash: a minus sign is a credit or a
     * refund (`TOTAL -£12.00`), which fills nothing.
     */
    private const string BETWEEN = '\s*(?:[:=]\s*)?(?:\((?:inc|incl|including|inkl)[^)]{0,20}\)\s*)?';

    public function __construct(private Keywords $keywords)
    {
    }

    /**
     * Candidates for the set search: each amount, *labelled* when a total
     * word comes before it on its row with no change, discount, saving,
     * subtotal or tendered word between them (`TOTAL £45.26 (saving
     * £2.00)`: 45.26 labelled, 2.00 not), and whether it is printed with a
     * currency.
     *
     * @param list<string> $rows
     * @return list<array{printed: string, read: string, row: string, labelled: bool, currency: bool}>
     */
    public function of(array $rows, string $locale): array
    {
        $found = [];
        $total = $this->keywords->pattern('total', $locale);
        $notTotal = $this->keywords->pattern('not_total', $locale);
        foreach ($rows as $row) {
            $text = RuleText::withoutDates($row);
            if (preg_match_all('/' . RuleText::NUMBER . '/u', $text, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            $labels = self::starts($total, $text);
            $blocks = self::ends($notTotal, $text);
            $label = -1;
            $block = -1;
            $li = 0;
            $bi = 0;
            foreach ($m[0] as [$printed, $offset]) {
                while ($li < count($labels) && $labels[$li] < $offset) {
                    $label = $labels[$li++];
                }
                while ($bi < count($blocks) && $blocks[$bi] <= $offset) {
                    $block = $blocks[$bi++];
                }
                $end = $offset + strlen($printed);
                $read = self::amount($printed, $text, $end, $locale);
                if ($read === null) {
                    continue;
                }
                $before = self::tail($text, $offset, 5);
                $found[] = [
                    'printed' => $printed,
                    'read' => $read,
                    'row' => $row,
                    'labelled' => $label >= 0 && $block <= $label,
                    'currency' => preg_match('/(?:[£€$]|(?:' . self::CODES . '))\s?$/iu', $before) === 1
                        || preg_match('/\G\s?(?:[£€$]|(?:' . self::CODES . ')\b)/iu', $text, $_, 0, $end) === 1,
                ];
            }
        }

        return $found;
    }

    /**
     * The grand total, strictly (#526): the first amount directly after a
     * word labelling the grand total (`TOTAL`, `AMOUNT DUE`), with at most
     * `:`, `(inc VAT)` or a currency between; never after a qualified
     * label (`Net total`, `Labour total`; a word after the label, `Total
     * labour 240`, is never "directly"); none when two different ones are
     * printed.
     *
     * @param list<string> $rows
     * @return array{printed: string, read: string, row: string}|null
     */
    public function grandTotal(array $rows, string $locale): ?array
    {
        $label = $this->keywords->pattern('invoice_total', $locale);
        $after = '/\G' . self::BETWEEN . '(?:([£€$]|(?:' . self::CODES . ')\b)\s?)?(' . RuleText::NUMBER . ')/iu';
        $found = [];
        foreach ($rows as $row) {
            $text = RuleText::withoutDates($row);
            if (preg_match_all($label, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as [$word, $offset]) {
                // Cut at a character boundary: a byte cut inside `é` made the match fail and skip the check
                // (merge review of 53.4). A match that can't run fills nothing.
                $before = self::tail($text, $offset, 30);
                $qualified = preg_match('/(\p{L}+)\W*$/u', $before, $w);
                if ($qualified === false || ($qualified === 1 && $this->keywords->has('total_qualifier', $locale, $w[1]))) {
                    continue;
                }
                $end = $offset + strlen($word);
                if (preg_match($after, $text, $a, PREG_OFFSET_CAPTURE, $end) !== 1) {
                    continue;
                }
                [$printed, $at] = $a[2];
                $numberEnd = $at + strlen($printed);
                $read = self::amount($printed, $text, $numberEnd, $locale);
                if ($read !== null && self::looksLikeMoney($printed, $a[1][1] >= 0, $text, $numberEnd)) {
                    $found[Decimal::trim($read)] ??= ['printed' => $printed, 'read' => $read, 'row' => $row];
                }
            }
        }

        return count($found) === 1 ? array_values($found)[0] : null;
    }

    /**
     * A grand total looks like money (fourth merge review): printed with a
     * currency, before or after it, or with exactly two decimals and no
     * word after it, so `Total 3 items`, `Total 2.5 hrs` and `Total 20.00
     * VAT` are never a total.
     */
    private static function looksLikeMoney(string $printed, bool $currencyBefore, string $text, int $end): bool
    {
        if ($currencyBefore || preg_match('/\G\s?(?:[£€$]|(?:' . self::CODES . ')\b)/iu', $text, $_, 0, $end) === 1) {
            return true;
        }

        return preg_match('/[.,]\d{2}$/', $printed) === 1 && preg_match('/\G\s*\p{L}/u', $text, $_, 0, $end) !== 1;
    }

    /**
     * The number read in the user's locale when it is an amount: above
     * zero, not followed by a unit, a price's `/` or `per`, or `%`.
     */
    private static function amount(string $printed, string $text, int $end, string $locale): ?string
    {
        $unit = '/\G\s?(?:[£€$]|' . self::CODES . ')?\s?(?:(?:' . self::UNIT_AFTER . ')(?![\p{L}])|\/|per\b|%)/iu';
        if (preg_match($unit, $text, $_, 0, $end) === 1) {
            return null;
        }
        $read = RuleText::read($printed, $locale);

        return $read !== null && Decimal::compare($read, '0') > 0 ? $read : null;
    }

    /**
     * Up to $bytes bytes of $text before $offset, cut at a character
     * boundary so the slice is valid UTF-8.
     */
    private static function tail(string $text, int $offset, int $bytes): string
    {
        $from = max(0, $offset - $bytes);

        return mb_strcut($text, $from, $offset - $from, 'UTF-8');
    }

    /**
     * @return list<int> where each match starts, in order
     */
    private static function starts(string $pattern, string $text): array
    {
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        return array_map(static fn (array $x): int => $x[1], $m[0]);
    }

    /**
     * @return list<int> where each match ends, in order
     */
    private static function ends(string $pattern, string $text): array
    {
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        return array_map(static fn (array $x): int => $x[1] + strlen($x[0]), $m[0]);
    }
}
