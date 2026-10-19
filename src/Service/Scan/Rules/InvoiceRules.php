<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Support\Number\Decimal;

/**
 * A service invoice read without AI, in part (spec.md §7.27 *Reading
 * without AI*, Phase 53.4): the date, the total, the registration, the
 * odometer and the work lines (#519); the garage is left for the owner
 * (#526). Each from a labelled
 * line only, so no value is filled from the wrong label.
 */
final readonly class InvoiceRules
{
    private const int MAX_WORK_LINES = 20;

    public function __construct(
        private Keywords $keywords,
        private Amounts $amounts,
    ) {
    }

    /**
     * @param list<string> $rows
     * @param list<string> $plates the registrations of the vehicles the user can log to
     * @param \Closure(string): bool $clear whether an amount was read clearly (#525)
     * @return array{fields: array<string, array{value: string, evidence: string}>, lines: array<string, list<string>>}
     */
    public function read(array $rows, string $locale, array $plates, \Closure $clear): array
    {
        $fields = [];
        $date = $this->date($rows, $locale);
        if ($date !== null) {
            $fields['date'] = $date;
        }
        $total = $this->total($rows, $locale);
        // Nothing checks an invoice's total, so only one read clearly is filled (#525).
        if ($total !== null && $clear($total['value'])) {
            $fields['total'] = $total;
            $currency = RuleText::currency($total['evidence']);
            if ($currency !== null) {
                $fields['currency'] = ['value' => $currency, 'evidence' => $total['evidence']];
            }
        }
        $registration = self::registration($rows, $plates);
        if ($registration !== null) {
            $fields['registration'] = $registration;
        }
        foreach ($this->odometer($rows, $locale) as $name => $field) {
            $fields[$name] = $field;
        }
        $work = $this->work($rows, $locale, $registration['value'] ?? null);

        return ['fields' => $fields, 'lines' => $work === [] ? [] : ['work' => $work]];
    }

    /**
     * The registration: a vehicle's of the user's printed in the text (any
     * spacing), else the first UK plate (`AB12 CDE`).
     *
     * @param list<string> $rows
     * @param list<string> $plates
     * @return array{value: string, evidence: string}|null
     */
    public static function registration(array $rows, array $plates): ?array
    {
        foreach ($rows as $row) {
            foreach ($plates as $plate) {
                $plain = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($plate));
                if ($plain !== '' && preg_match('/(?<![A-Z0-9])' . self::spaced($plain) . '(?![A-Z0-9])/iu', $row, $m) === 1) {
                    return ['value' => $m[0], 'evidence' => RuleText::evidence($row)];
                }
            }
        }
        foreach ($rows as $row) {
            if (preg_match('/(?<![A-Za-z0-9])[A-Z]{2}[0-9]{2}\s?[A-Z]{3}(?![A-Za-z0-9])/u', $row, $m) === 1) {
                return ['value' => $m[0], 'evidence' => RuleText::evidence($row)];
            }
        }

        return null;
    }

    /**
     * The first date on a line with a date word, else the first date.
     *
     * @param list<string> $rows
     * @return array{value: string, evidence: string}|null
     */
    private function date(array $rows, string $locale): ?array
    {
        $first = null;
        foreach ($rows as $row) {
            $date = $this->ownDate($row, $locale);
            if ($date === null) {
                continue;
            }
            $field = ['value' => $date, 'evidence' => RuleText::evidence($row)];
            if ($this->keywords->has('date', $locale, $row)) {
                return $field;
            }
            $first ??= $field;
        }

        return $first;
    }

    /**
     * The largest amount on a line with an invoice total word, never a
     * subtotal's.
     *
     * @param list<string> $rows
     * @return array{value: string, evidence: string}|null
     */
    private function total(array $rows, string $locale): ?array
    {
        // Strictly (#526): an amount after a word labelling the total itself, never a subtotal's.
        $best = $this->amounts->grandTotal($rows, $locale);

        return $best === null ? null : ['value' => $best['printed'], 'evidence' => RuleText::evidence($best['row'])];
    }

    /**
     * The number after an odometer word (`Mileage: 48,120`, `Odometer
     * 63,480 mi`), with its unit when printed.
     *
     * @param list<string> $rows
     * @return array<string, array{value: string, evidence: string}>
     */
    private function odometer(array $rows, string $locale): array
    {
        $pattern = '/(?<![\p{L}\p{N}])' . $this->keywords->alternation('odometer', $locale)
            . '\s*[:.#=-]?\s*(' . RuleText::NUMBER . ')(?:\s?(km|kms|mi|miles|mls)(?![\p{L}]))?/iu';
        foreach ($rows as $row) {
            if (preg_match($pattern, RuleText::withoutDates($row), $m) !== 1 || RuleText::read($m[1], $locale) === null) {
                continue;
            }
            $evidence = RuleText::evidence($row);
            $fields = ['odometer' => ['value' => $m[1], 'evidence' => $evidence]];
            if (($m[2] ?? '') !== '') {
                $fields['odometer_unit'] = ['value' => $m[2], 'evidence' => $evidence];
            }

            return $fields;
        }

        return [];
    }

    /**
     * The work lines (#519): the rows after the header's last labelled row
     * (a date, the registration, the mileage, the invoice number) and
     * before the first totals row, unparsed. None without a header.
     *
     * @param list<string> $rows
     * @return list<string>
     */
    private function work(array $rows, string $locale, ?string $registration): array
    {
        $end = null;
        foreach ($rows as $i => $row) {
            if ($this->isTotals($row, $locale)) {
                $end = $i;
                break;
            }
        }
        $header = null;
        foreach (array_slice($rows, 0, $end ?? count($rows)) as $i => $row) {
            $plate = $registration !== null && str_contains(mb_strtoupper($row), mb_strtoupper($registration));
            if ($plate || $this->keywords->has('header', $locale, $row) || RuleText::date($row, $locale) !== null) {
                $header = $i;
            }
        }
        if ($header === null || $end === null) {
            return [];
        }
        $lines = [];
        foreach (array_slice($rows, $header + 1, $end - $header - 1) as $row) {
            $row = trim((string) preg_replace('/\s+/u', ' ', $row));
            if (preg_match('/\p{L}{2}/u', $row) === 1) {
                $lines[] = $row;
            }
        }

        return array_slice($lines, 0, self::MAX_WORK_LINES);
    }

    /**
     * The row's first date that is the document's own: a date with a word
     * such as *next*, *due* or *expiry* between it and the date (or row
     * start) before it is another date (`Next service due 01/09/2026
     * Invoice date 01/03/2025` gives 01/03/2025; merge review of 53.4).
     */
    private function ownDate(string $row, string $locale): ?string
    {
        $from = 0;
        foreach (RuleText::dates($row, $locale) as [$date, $offset]) {
            if (!$this->keywords->has('not_date', $locale, substr($row, $from, $offset - $from))) {
                return $date;
            }
            $from = $offset + strlen($date);
        }

        return null;
    }

    private function isTotals(string $row, string $locale): bool
    {
        return preg_match('/^\s*' . $this->keywords->alternation('totals', $locale) . '(?![\p{L}\p{N}])/iu', $row) === 1
            || $this->keywords->has('invoice_total', $locale, $row);
    }

    /**
     * A plate's letters and digits with any spaces or dashes between them.
     */
    private static function spaced(string $plain): string
    {
        return implode('[\s\-]*', array_map(static fn (string $c): string => preg_quote($c, '/'), mb_str_split($plain)));
    }
}
