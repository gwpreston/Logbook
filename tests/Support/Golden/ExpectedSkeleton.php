<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Golden;

use RuntimeException;

/**
 * The rows of tests/Fixtures/golden/expected.csv, with the `expected`
 * column empty: every figure the golden test checks, for the owner's
 * spreadsheet to fill (Phase 45.1, #393). Economy segments are listed from
 * the fill-ups, one per full fill after a vehicle's first.
 */
final class ExpectedSkeleton
{
    private const array CURRENCY = ['golf' => 'GBP', 'ev' => 'GBP', 'focus' => 'GBP', 'corolla' => 'EUR'];
    private const string BOTH = 'spreadsheet+script';
    private const array PARTS = ['fuel', 'maintenance', 'compliance', 'other', 'depreciation', 'total'];

    /** @var list<array<string, string>> */
    private array $rows = [];

    /**
     * @param list<array<string, string>> $fillUps the rows of inputs/fillups.csv
     * @return list<array<string, string>>
     */
    public function rows(array $fillUps): array
    {
        $this->rows = [];
        $this->ownership();
        $this->trueCost();
        $this->finance();
        $this->claims();
        $this->comingUp();
        $this->economy($fillUps);
        $this->reports();
        $this->digest();
        $this->add('hidden.golf_costs', 'sam', 'golf', 'all', '', 'none', 'spreadsheet', 'Sam gets no amount for the Golf');

        return $this->rows;
    }

    /**
     * Write the skeleton, refusing to overwrite a file whose `expected`
     * column has been filled in.
     */
    public function write(string $inputs, string $file): void
    {
        if (is_file($file) && $this->hasFigures($file)) {
            throw new RuntimeException("{$file} already has expected figures; not overwritten");
        }
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
        $rows = $this->rows(self::read($inputs . '/fillups.csv'));
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);
    }

    /**
     * @return list<array<string, string>>
     */
    public static function read(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$file}");
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($header === false || $line === [null]) {
                continue;
            }
            $rows[] = array_combine(
                array_map('strval', $header),
                array_map(static fn (?string $value): string => (string) $value, $line),
            );
        }
        fclose($handle);

        return $rows;
    }

    private function hasFigures(string $file): bool
    {
        foreach (self::read($file) as $row) {
            if (($row['expected'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    private function add(
        string $figure,
        string $viewer,
        string $vehicle,
        string $period,
        string $currency,
        string $unit,
        string $source = 'spreadsheet',
        string $notes = ''
    ): void {
        $this->rows[] = [
            'id' => (string) (count($this->rows) + 1), 'figure' => $figure, 'viewer' => $viewer, 'vehicle' => $vehicle,
            'period' => $period, 'currency' => $currency, 'unit' => $unit, 'expected' => '', 'source' => $source,
            'notes' => $notes,
        ];
    }

    private function ownership(): void
    {
        foreach (self::CURRENCY as $vehicle => $currency) {
            $this->add('ownership.total', 'alex', $vehicle, 'since_bought', $currency, 'money', self::BOTH);
            $this->add('ownership.running', 'alex', $vehicle, 'since_bought', $currency, 'money');
            $this->add('ownership.depreciation', 'alex', $vehicle, 'since_bought', $currency, 'money', self::BOTH);
            $this->add('ownership.per_distance', 'alex', $vehicle, 'since_bought', $currency, 'money/mi', self::BOTH);
            $this->add('ownership.per_month', 'alex', $vehicle, 'since_bought', $currency, 'money/month', self::BOTH);
        }
        $this->add('ownership.payouts', 'alex', 'focus', 'since_bought', 'GBP', 'money');
        $this->add('ownership.total', 'sam', 'corolla', 'since_bought', 'EUR', 'money', self::BOTH);
        $this->add('ownership.per_distance', 'sam', 'corolla', 'since_bought', 'EUR', 'money/km', self::BOTH);
        $this->add('ownership.per_month', 'sam', 'corolla', 'since_bought', 'EUR', 'money/month', self::BOTH);
    }

    private function trueCost(): void
    {
        $periods = [
            'golf' => ['last_12_months', '2023', '2024', '2025', '2026'],
            'ev' => ['last_12_months', '2024', '2025', '2026'],
            'focus' => ['2023', '2024', '2025'],
            'corolla' => ['last_12_months', '2023', '2024', '2025', '2026'],
        ];
        foreach ($periods as $vehicle => $list) {
            $currency = self::CURRENCY[$vehicle];
            foreach ($list as $period) {
                foreach (self::PARTS as $part) {
                    $this->add('true_cost.' . $part, 'alex', $vehicle, $period, $currency, 'money', self::sourceOf($part));
                }
                if ($vehicle === 'focus') {
                    $this->add('true_cost.payouts', 'alex', $vehicle, $period, $currency, 'money');
                }
                $this->add('true_cost.per_distance', 'alex', $vehicle, $period, $currency, 'money/mi', self::BOTH);
            }
            if (in_array('last_12_months', $list, true)) {
                foreach (self::PARTS as $part) {
                    $this->add(
                        'true_cost.what_changed.' . $part,
                        'alex',
                        $vehicle,
                        'last_12_months_vs_previous',
                        $currency,
                        'money',
                        self::sourceOf($part)
                    );
                }
            }
        }
    }

    private static function sourceOf(string $part): string
    {
        return in_array($part, ['depreciation', 'total'], true) ? self::BOTH : 'spreadsheet';
    }

    private function finance(): void
    {
        foreach (['golf-pcp' => 'golf', 'ev-hp' => 'ev'] as $agreement => $vehicle) {
            foreach (
                [
                    'finance.payments_made' => 'count', 'finance.payments_remaining' => 'count', 'finance.amount_paid' => 'money',
                    'finance.interest_so_far' => 'money', 'finance.cost_of_credit' => 'money',
                ] as $figure => $unit
            ) {
                $this->add($figure, 'alex', $vehicle, 'today', self::CURRENCY[$vehicle], $unit, self::BOTH, $agreement);
            }
        }
        $this->add('finance.settlement_estimate', 'alex', 'ev', 'today', 'GBP', 'money', self::BOTH, 'ev-hp');
        $this->add('finance.equity', 'alex', 'ev', 'today', 'GBP', 'money', self::BOTH, 'ev-hp');
    }

    private function claims(): void
    {
        foreach (['2023-24', '2024-25', '2025-26', '2026-27'] as $year) {
            $this->add('claim.business_distance', 'alex', 'golf', 'tax_year ' . $year, '', 'mi');
            $this->add('claim.amount', 'alex', 'golf', 'tax_year ' . $year, 'GBP', 'money');
        }
    }

    private function comingUp(): void
    {
        foreach (['alex' => ['GBP', 'EUR'], 'sam' => ['EUR']] as $viewer => $currencies) {
            foreach ($currencies as $currency) {
                foreach (['next_3_months', 'next_12_months'] as $horizon) {
                    $this->add(
                        'coming_up.total',
                        $viewer,
                        'all',
                        $horizon,
                        $currency,
                        'money',
                        'spreadsheet',
                        'put at_least here when any item has no known cost'
                    );
                }
            }
        }
    }

    /**
     * @param list<array<string, string>> $fillUps
     */
    private function economy(array $fillUps): void
    {
        foreach (array_keys(self::CURRENCY) as $vehicle) {
            $viewer = $vehicle === 'corolla' ? 'sam' : 'alex';
            $unit = $vehicle === 'ev' ? 'kWh/100km' : 'l/100km';
            $this->add('economy.lifetime', $viewer, $vehicle, 'all_time', '', $unit);
            $own = array_values(array_filter($fillUps, static fn (array $row): bool => $row['vehicle'] === $vehicle));
            usort($own, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $seen = false;
            foreach ($own as $row) {
                if ($row['partial'] === 'no') {
                    if ($seen) {
                        $this->add('economy.segment', $viewer, $vehicle, 'segment ending ' . $row['at'], '', $unit);
                    }
                    $seen = true;
                }
            }
        }
    }

    private function reports(): void
    {
        $years = [
            'golf' => ['2023', '2024', '2025', '2026'], 'ev' => ['2024', '2025', '2026'],
            'focus' => ['2023', '2024', '2025'], 'corolla' => ['2023', '2024', '2025', '2026'],
        ];
        foreach ($years as $vehicle => $list) {
            $currency = self::CURRENCY[$vehicle];
            foreach ($list as $year) {
                foreach (['fuel', 'maintenance', 'compliance', 'other', 'total'] as $group) {
                    $this->add('reports.group.' . $group, 'alex', $vehicle, $year, $currency, 'money');
                }
            }
            foreach (['tax', 'parking', 'tolls', 'accessories', 'finance'] as $category) {
                $this->add('reports.category.' . $category, 'alex', $vehicle, 'all_time', $currency, 'money');
            }
            $this->add('reports.cost_per_distance', 'alex', $vehicle, 'all_time', $currency, 'money/mi');
        }
        foreach (['2023', '2024', '2025', '2026'] as $year) {
            foreach (['GBP', 'EUR'] as $currency) {
                $this->add('reports.fleet.total', 'alex', 'fleet', $year, $currency, 'money');
            }
        }
    }

    private function digest(): void
    {
        $views = ['alex' => [['golf', 'ev', 'corolla'], 'mi', ['GBP', 'EUR']], 'sam' => [['golf', 'corolla'], 'km', ['EUR']]];
        foreach ($views as $viewer => [$vehicles, $unit, $currencies]) {
            foreach ($vehicles as $vehicle) {
                $this->add('digest.distance', $viewer, $vehicle, '2026-08', '', $unit);
                $this->add('digest.distance_average', $viewer, $vehicle, '2026-08', '', $unit);
                if ($viewer === 'sam' && $vehicle === 'golf') {
                    continue;
                }
                $currency = self::CURRENCY[$vehicle];
                $this->add('digest.spend', $viewer, $vehicle, '2026-08', $currency, 'money');
                $this->add('digest.spend_average', $viewer, $vehicle, '2026-08', $currency, 'money');
                $this->add('digest.cost_per_distance', $viewer, $vehicle, '2026-08', $currency, 'money/' . $unit);
                $this->add('digest.cost_per_distance_average', $viewer, $vehicle, '2026-08', $currency, 'money/' . $unit);
            }
            $this->add('digest.fleet.distance', $viewer, 'fleet', '2026-08', '', $unit);
            foreach ($currencies as $currency) {
                $this->add('digest.fleet.spend', $viewer, 'fleet', '2026-08', $currency, 'money');
            }
        }
    }
}
