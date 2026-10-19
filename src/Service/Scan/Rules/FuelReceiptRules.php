<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Service\Ai\Scan\FuelSum;
use Logbook\Support\Number\Decimal;

/**
 * A fuel receipt read without AI (spec.md §7.27 *Reading without AI*,
 * Phase 53.4). Only labelled numbers are candidates: a volume printed
 * with its unit (or after a volume word), a price per unit (`p/L`, `PPL`,
 * `£/L`, `/kWh`, `per litre`, or after a price word), and a total (on a
 * line with a total word, or printed with its currency). The volume, price
 * and total whose sum agrees by *The money adds up*'s own rule (FuelSum)
 * win; with none, or two that disagree, only the total is filled (the
 * largest amount on a total line, and only when OCR read that amount
 * clearly, #525), and the volume and price are left for the owner. A
 * wrong agreeing set is never filled.
 */
final readonly class FuelReceiptRules
{
    private const string UNITS = 'litres|liters|litre|liter|ltrs|ltr|kwh|gallons|gallon|gals|gal|l|g';
    private const string PER_UNIT = 'litres|liters|litre|liter|ltrs|ltr|kwh|gallons|gallon|gal|l|g';
    private const string CODES = 'GBP|EUR|USD|CHF';
    private const string PENCE = 'pence|cents|cent|ct|p|c';
    /**
     * A receipt prints a few candidates of each. Past this many different
     * volumes, prices or totals (a statement, a long list) no set is
     * searched, so the search stays bounded (merge review of 53.4).
     */
    private const int MAX_CANDIDATES = 12;

    public function __construct(
        private Keywords $keywords,
        private Amounts $amounts,
    ) {
    }

    /**
     * What the receipt prints that only a fuel receipt does: 2 with a
     * volume, 1 with only a price per unit, else 0 (for the classifier).
     *
     * @param list<string> $rows
     */
    public function printed(array $rows, string $locale): int
    {
        return match (true) {
            $this->volumes($rows, $locale) !== [] => 2,
            $this->prices($rows, $locale) !== [] => 1,
            default => 0,
        };
    }

    /**
     * The fields read, as a model gives them: `{value, evidence}` each,
     * values as printed, the evidence the row they came from.
     *
     * @param list<string> $rows
     * @param \Closure(string): bool $knownStation whether a name is one of the install's stations
     * @param \Closure(string): bool $clear whether an amount was read clearly (#525)
     * @return array<string, array{value: string, evidence: string}>
     */
    public function read(array $rows, string $locale, \Closure $knownStation, \Closure $clear): array
    {
        $fields = [];
        foreach ($rows as $row) {
            $date = RuleText::date($row, $locale);
            if ($date !== null) {
                $fields['date'] = self::field($date, $row);
                $time = RuleText::time($row);
                if ($time !== null) {
                    $fields['time'] = self::field($time, $row);
                }
                break;
            }
        }

        $total = null;
        $set = $this->agreeing($rows, $locale);
        if ($set !== null && isset($set['volume'], $set['price'])) {
            $fields['volume'] = self::field($set['volume']['printed'], $set['volume']['row']);
            $fields['volume_unit'] = self::field($set['volume']['unit'], $set['volume']['row']);
            $fields['price_per_unit'] = self::field($set['price']['printed'], $set['price']['row']);
            $total = $set['total'];
        } else {
            // A total no set agrees with is filled only strictly (#526), and read clearly (#525):
            // OCR's 0 read as 6 adds up to nothing.
            $lone = Amounts::largestExact($this->amounts->of($rows, $locale));
            $total = $set['total'] ?? ($lone === null ? null : ['printed' => $lone['printed'], 'row' => $lone['row']]);
            if ($set === null && $total !== null && !$clear($total['printed'])) {
                $total = null;
            }
        }
        if ($total !== null) {
            $fields['total'] = self::field($total['printed'], $total['row']);
            $currency = RuleText::currency($total['row']);
            if ($currency !== null) {
                $fields['currency'] = self::field($currency, $total['row']);
            }
        }

        $grade = $this->keywords->pattern('grade', $locale);
        foreach ($rows as $row) {
            if (preg_match_all($grade, $row, $m) > 0) {
                $fields['grade'] = self::field(implode(' ', $m[0]), $row);
                break;
            }
        }

        $station = self::station($rows, $knownStation);
        if ($station !== null) {
            $fields['vendor'] = self::field($station[0], $station[1]);
        }

        return $fields;
    }

    /**
     * The station: only one of the install's stations, named on one of
     * the top five rows; otherwise none, for the owner to pick (#526: a
     * first row is as often an address or a product as a name).
     *
     * @param list<string> $rows
     * @param \Closure(string): bool $known
     * @return array{string, string}|null the name and its row
     */
    private static function station(array $rows, \Closure $known): ?array
    {
        foreach (array_slice($rows, 0, 5) as $row) {
            $name = RuleText::name($row);
            if ($name !== null && $known($name)) {
                return [$name, $row];
            }
        }

        return null;
    }

    /**
     * The one volume, price and total that agree; or, when sets that agree
     * share only their total, that total alone; else null.
     *
     * @param list<string> $rows
     * @return array{
     *     volume?: array{printed: string, unit: string, row: string},
     *     price?: array{printed: string, row: string},
     *     total: array{printed: string, row: string},
     * }|null
     */
    private function agreeing(array $rows, string $locale): ?array
    {
        $volumes = self::distinct($this->volumes($rows, $locale), static fn (array $v): string => $v['read']);
        $prices = self::distinct(
            $this->prices($rows, $locale),
            static fn (array $p): string => $p['read'] . '|' . var_export($p['pence'], true),
        );
        $totals = self::distinct($this->totals($rows, $locale), static fn (array $t): string => $t['read']);
        if (max(count($volumes), count($prices), count($totals)) > self::MAX_CANDIDATES) {
            return null;
        }
        $sets = [];
        foreach ($volumes as $volume) {
            foreach ($prices as $price) {
                foreach ($totals as $total) {
                    foreach ($price['pence'] === null ? [false, true] : [$price['pence']] as $pence) {
                        if (!FuelSum::agrees($volume['read'], $price['read'], $total['read'], $pence)) {
                            continue;
                        }
                        $perUnit = $pence ? Decimal::divide($price['read'], '100', 6) : $price['read'];
                        $key = implode('|', array_map(Decimal::trim(...), [$volume['read'], $perUnit, $total['read']]));
                        $sets[$key] ??= [
                            'volume' => ['printed' => $volume['printed'], 'unit' => $volume['unit'], 'row' => $volume['row']],
                            'price' => ['printed' => $price['number'] . ($pence ? 'p' : ''), 'row' => $price['row']],
                            'total' => ['printed' => $total['printed'], 'row' => $total['row'], 'read' => $total['read']],
                        ];
                    }
                }
            }
        }
        if ($sets === []) {
            return null;
        }
        $sets = array_values($sets);
        if (count($sets) === 1) {
            $only = $sets[0];

            return [
                'volume' => $only['volume'],
                'price' => $only['price'],
                'total' => ['printed' => $only['total']['printed'], 'row' => $only['total']['row']],
            ];
        }
        $totals = array_unique(array_map(static fn (array $set): string => Decimal::trim($set['total']['read']), $sets));
        if (count($totals) === 1) {
            return ['total' => ['printed' => $sets[0]['total']['printed'], 'row' => $sets[0]['total']['row']]];
        }

        return null;
    }

    /**
     * @param list<string> $rows
     * @return list<array{printed: string, read: string, unit: string, row: string}>
     */
    private function volumes(array $rows, string $locale): array
    {
        $after = '/(?<![£€$])(?<![£€$]\s)(' . RuleText::NUMBER . ')\s?(' . self::UNITS . ')(?![\p{L}\d\/])/iu';
        $label = '/(?<![\p{L}\p{N}])' . $this->keywords->alternation('volume', $locale)
            . '\s*[:=]?\s*(' . RuleText::NUMBER . ')(?!\s?(?:[£€$\/%]|' . self::PENCE . '\b|ppl\b))/iu';
        $found = [];
        foreach ($rows as $row) {
            $text = RuleText::withoutDates($row);
            $matches = [];
            if (preg_match_all($after, $text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    $matches[] = [$match[1], $match[2]];
                }
            }
            if (preg_match_all($label, $text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    $matches[] = [$match[1], ''];
                }
            }
            foreach ($matches as [$printed, $unit]) {
                $read = self::positive($printed, $locale);
                if ($read !== null) {
                    $found[] = ['printed' => $printed, 'read' => $read, 'unit' => $unit, 'row' => $row];
                }
            }
        }

        return $found;
    }

    /**
     * Prices per unit, $pence true (printed in pence or cents), false
     * (printed with a currency) or null (could be either).
     *
     * @param list<string> $rows
     * @return list<array{number: string, read: string, pence: ?bool, row: string}>
     */
    private function prices(array $rows, string $locale): array
    {
        $digits = RuleText::DIGITS;
        $perUnit = '/(?<![\p{L}\d.,])([£€$]\s?)?(' . $digits . ')\s?(' . self::PENCE . ')?\s?(?:([£€$]|' . self::CODES . ')\s?)?'
            . '(?:\/|per\s+)\s?(?:' . self::PER_UNIT . ')(?![\p{L}])/iu';
        $ppl = '/(?<![\p{L}\d.,])(' . $digits . ')\s?ppl(?![\p{L}])|(?<![\p{L}])ppl\s*[:=]?\s*(' . $digits . ')(?!\d)/iu';
        $label = '/(?<![\p{L}\p{N}])' . $this->keywords->alternation('price', $locale)
            . '\s*[:=]?\s*([£€$]\s?)?(' . $digits . ')(?!\d)\s?(' . self::PENCE . ')?(?![\p{L}])/iu';
        $found = [];
        foreach ($rows as $row) {
            $text = RuleText::withoutDates($row);
            $matches = [];
            if (preg_match_all($perUnit, $text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    $matches[] = [$match[2], self::pence($match[1], $match[3] ?? '', $match[4] ?? '')];
                }
            }
            if (preg_match_all($ppl, $text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    $matches[] = [($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''), true];
                }
            }
            if (preg_match_all($label, $text, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $match) {
                    $matches[] = [$match[2], self::pence($match[1], $match[3] ?? '', '')];
                }
            }
            foreach ($matches as [$number, $pence]) {
                $read = self::positive($number, $locale);
                if ($read !== null) {
                    $found[] = ['number' => $number, 'read' => $read, 'pence' => $pence, 'row' => $row];
                }
            }
        }

        return $found;
    }

    /**
     * The total candidates for the set search: labelled amounts, and
     * amounts printed with a currency while there are at most 12 different
     * ones (a shop basket's prices would pass the cap, merge review).
     *
     * @param list<string> $rows
     * @return list<array{printed: string, read: string, row: string, labelled: bool, exact: bool, currency: bool}>
     */
    private function totals(array $rows, string $locale): array
    {
        $all = array_values(array_filter(
            $this->amounts->of($rows, $locale),
            static fn (array $a): bool => $a['labelled'] || $a['currency'],
        ));
        $values = array_unique(array_map(static fn (array $a): string => Decimal::trim($a['read']), $all));
        if (count($values) <= self::MAX_CANDIDATES) {
            return $all;
        }

        return array_values(array_filter($all, static fn (array $a): bool => $a['labelled']));
    }

    /**
     * The first of each value, in order.
     *
     * @template T of array<string, mixed>
     * @param list<T> $candidates
     * @param \Closure(T): string $key
     * @return list<T>
     */
    private static function distinct(array $candidates, \Closure $key): array
    {
        $seen = [];
        foreach ($candidates as $candidate) {
            $seen[$key($candidate)] ??= $candidate;
        }

        return array_values($seen);
    }

    private static function pence(string $symbol, string $pence, string $code): ?bool
    {
        return match (true) {
            $pence !== '' => true,
            trim($symbol) !== '' || $code !== '' => false,
            default => null,
        };
    }

    /**
     * A found number read in the user's locale, when above zero.
     */
    private static function positive(string $printed, string $locale): ?string
    {
        $read = RuleText::read($printed, $locale);

        return $read !== null && Decimal::compare($read, '0') > 0 ? $read : null;
    }

    /**
     * @return array{value: string, evidence: string}
     */
    private static function field(string $value, string $row): array
    {
        return ['value' => trim($value), 'evidence' => RuleText::evidence($row)];
    }
}
