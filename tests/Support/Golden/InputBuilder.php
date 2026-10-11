<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Golden;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Builds the golden household's inputs (Phase 45.1, spec.md §8 *Money edge
 * cases*): the CSV files in tests/Fixtures/golden/inputs/, which the owner's
 * spreadsheet and the golden test both start from.
 *
 * The CSV files are the fixture, committed as written; this class is how
 * they were made, kept so a change to the household is a reviewed change to
 * the script. It shares no code with src/ and does no money arithmetic
 * beyond a pump's own (litres × price, rounded to the penny, as printed on
 * the receipt).
 *
 * Conventions (see tests/Fixtures/golden/README.md):
 * - distances and odometers in km to 3 places, volumes in litres or kWh to
 *   3 places, prices per unit to 3 places, amounts in the vehicle's currency
 *   to 2 places;
 * - a time is local wall-clock time in the zone of the user in `by`;
 * - a date is a calendar date, never converted.
 */
final class InputBuilder
{
    public const string TODAY_UTC = '2026-09-15T08:00:00Z';
    private const int SEED = 451;
    private const string END = '2026-09-14';

    /** @var array<string, list<array<string, string>>> */
    private array $files = [];
    /** @var array<string, array<string, array{int, int}>> each vehicle's odometer at the start of a day, and that day's metres */
    private array $days = [];
    private Rng $rng;

    /** @var array<string, string> each user's zone */
    private const array ZONES = ['alex' => 'Europe/London', 'sam' => 'Europe/Dublin'];

    public function __construct()
    {
        $this->rng = new Rng(self::SEED);
    }

    /**
     * Write every CSV into $dir (created when missing).
     */
    public function write(string $dir): void
    {
        $this->build();
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        foreach ($this->files as $name => $rows) {
            $handle = fopen($dir . '/' . $name . '.csv', 'wb');
            if ($handle === false) {
                throw new RuntimeException("Cannot write {$name}.csv");
            }
            fputcsv($handle, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row), ',', '"', '');
            }
            fclose($handle);
        }
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    public function build(): array
    {
        $this->files = [];
        $this->days = [];
        $this->rng = new Rng(self::SEED);
        $this->people();
        $this->golf();
        $this->ev();
        $this->focus();
        $this->corolla();
        ksort($this->files);
        foreach ($this->files as &$rows) {
            usort($rows, static fn (array $a, array $b): int => [$a['vehicle'] ?? $a['user'] ?? '', self::sortKey($a)]
                <=> [$b['vehicle'] ?? $b['user'] ?? '', self::sortKey($b)]);
        }
        unset($rows);

        return $this->files;
    }

    /**
     * @param array<string, string> $row
     */
    private static function sortKey(array $row): string
    {
        foreach (['at', 'date', 'valued_on', 'start_on', 'effective_from', 'started_on', 'occurred_on'] as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $row
     */
    private function add(string $file, array $row): void
    {
        $this->files[$file][] = $row;
    }

    // ---------------------------------------------------------------- people

    private function people(): void
    {
        $this->add('users', [
            'user' => 'alex', 'display_name' => 'Alex', 'locale' => 'en_GB', 'timezone' => self::ZONES['alex'],
            'currency' => 'GBP', 'distance_unit' => 'mi', 'volume_unit' => 'l', 'tax_year_start' => '04-06',
            'is_admin' => 'yes',
        ]);
        $this->add('users', [
            'user' => 'sam', 'display_name' => 'Sam', 'locale' => 'en_IE', 'timezone' => self::ZONES['sam'],
            'currency' => 'EUR', 'distance_unit' => 'km', 'volume_unit' => 'l', 'tax_year_start' => '01-01',
            'is_admin' => 'no',
        ]);
        // Sam logs fill-ups on the Golf without seeing its costs; Alex sees the Corolla, costs included.
        $this->add('shares', ['vehicle' => 'golf', 'user' => 'sam', 'level' => 'log', 'costs' => 'no', 'date' => '2023-10-07']);
        $this->add('shares', ['vehicle' => 'corolla', 'user' => 'alex', 'level' => 'view', 'costs' => 'yes',
            'date' => '2023-11-04']);
    }

    // ------------------------------------------------------------- vehicles

    /**
     * @param array<string, string> $fields
     */
    private function vehicle(string $key, string $owner, array $fields): void
    {
        $this->add('vehicles', ['vehicle' => $key, 'owner' => $owner] + $fields + [
            'sale_date' => '', 'sale_price' => '',
        ]);
    }

    /**
     * Petrol car, bought used on PCP: three valuations (the latest stale by
     * today), a balloon settled early in year three, tyres, two services, an
     * MOT fail then pass, Alex's business trips, and fill-ups by Sam.
     */
    private function golf(): void
    {
        $this->vehicle('golf', 'alex', [
            'type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf', 'variant' => '1.5 TSI Life', 'fuel_type' => 'petrol',
            'registration' => 'YH20 KLM', 'currency' => 'GBP', 'first_registered_on' => '2020-03-02',
            'purchase_date' => '2023-10-07', 'purchase_price' => '17995.00',
        ]);
        $this->reading('golf', 'alex', '2023-10-07 12:00', 38412000, 'Odometer when bought');

        $this->fuelRun('golf', [
            'from' => '2023-10-08', 'to' => self::END, 'odometer' => 38412000, 'km_per_day' => 62, 'per_100' => [5900, 6600],
                'tank' => 50000, 'fuel' => 'petrol',
            'prices' => ['2023-10' => 1529, '2024-04' => 1479, '2024-10' => 1399, '2025-04' => 1419,
                '2025-10' => 1359, '2026-09' => 1379],
            'stations' => ['Shell Leeds Road', 'Tesco Birstall', 'BP Ring Road', 'Esso Wakefield'],
            'partial_one_in' => 9, 'by_sam_one_in' => 6,
            // Alex at 23:30 on the last day of August: counted in August.
            'forced' => ['2025-08-31 23:30' => 'alex'],
        ]);

        // Finance: PCP, settled early in year three (spec §7.32).
        $this->add('finance', [
            'vehicle' => 'golf', 'agreement' => 'golf-pcp', 'type' => 'pcp', 'lender' => 'Northway Motor Finance',
            'started_on' => '2023-10-07', 'first_payment_on' => '2023-11-07', 'number_of_payments' => '36',
            'first_payment' => '', 'regular_payment' => '289.40', 'final_payment' => '7650.00',
            'final_payment_on' => '2026-11-07', 'cash_price' => '17995.00', 'customer_deposit' => '2000.00',
            'dealer_contribution' => '500.00', 'amount_of_credit' => '15495.00', 'apr' => '6.9',
            'documentation_fee' => '', 'option_to_purchase_fee' => '10.00', 'annual_mileage_allowance' => '15000',
            'mileage_unit' => 'mi', 'excess_mileage_charge' => '0.0800', 'start_odometer_km' => '38412.000',
            'count_in_costs' => 'yes',
        ]);
        $this->add('finance_events', [
            'vehicle' => 'golf', 'agreement' => 'golf-pcp', 'kind' => 'settlement', 'date' => '2026-05-15',
            'due_on' => '', 'amount' => '9240.66', 'note' => 'Settled early (balloon included)',
        ]);
        $this->add('finance_events', [
            'vehicle' => 'golf', 'agreement' => 'golf-pcp', 'kind' => 'end', 'date' => '2026-05-15',
            'due_on' => '', 'amount' => '9240.66', 'note' => 'Ended as settled',
        ]);

        foreach (
            [['2024-04-10', '16100.00', 'Dealer part-exchange quote'], ['2024-10-01', '14900.00', 'Online guide'],
            ['2025-06-30', '13400.00', 'Online guide (stale by today)']] as [$on, $amount, $source]
        ) {
            $this->add('valuations', ['vehicle' => 'golf', 'valued_on' => $on, 'amount' => $amount, 'source' => $source]);
        }

        // Services, tyres, the MOT fail and the free retest (a £0 document is not a ledger line).
        $this->maintenance('golf', '2024-03-14', 'service', 'Annual service', '289.00', 'Leeds VW');
        $this->maintenance('golf', '2024-11-22', 'tyres', 'Two front tyres', '412.50', 'Kwik Fit');
        $this->maintenance('golf', '2025-03-20', 'service', 'Major service', '345.00', 'Leeds VW');
        $this->maintenance('golf', '2025-09-18', 'other', 'MOT test (failed: front brake discs)', '54.85', 'Kwik Fit');
        $this->maintenance('golf', '2025-09-19', 'brakes', 'Front discs and pads', '286.40', 'Kwik Fit');
        $this->document('golf', 'inspection', 'MOT', 'Kwik Fit', '2024-09-20', '2025-09-19', '54.85');
        $this->document('golf', 'inspection', 'MOT (retest, free)', 'Kwik Fit', '2025-09-20', '2026-09-19', '0.00');
        $this->insurance('golf', 'Admiral', '2023-10-07', ['612.34', '688.10', '731.95']);
        $this->schedule('golf', 'service', 'Annual service', '24140.000', '12', '2025-03-20');

        // Vehicle tax (an ad-hoc *tax* expense), yearly from the purchase month.
        foreach (['2023-10-07' => '190.00', '2024-10-01' => '190.00', '2025-10-01' => '195.00'] as $on => $amount) {
            $this->expense('golf', $on, 'tax', $amount, 'Vehicle tax');
        }
        $this->parking('golf', '2023-10-10', self::END, 2);

        // Mileage rates: HMRC's from 6 April 2023; a band change mid-year on 1 Oct 2025 keeps the running total.
        $this->add('rate_sets', [
            'user' => 'alex', 'effective_from' => '2023-04-06', 'distance_unit' => 'mi', 'currency' => 'GBP',
            'car_rate' => '0.45', 'car_threshold' => '10000', 'car_rate_after' => '0.25', 'source' => 'HMRC AMAP',
        ]);
        $this->add('rate_sets', [
            'user' => 'alex', 'effective_from' => '2025-10-01', 'distance_unit' => 'mi', 'currency' => 'GBP',
            'car_rate' => '0.50', 'car_threshold' => '10000', 'car_rate_after' => '0.30',
                'source' => 'Employer, from October 2025',
        ]);
        $this->trips();
    }

    /**
     * Alex's business trips in the Golf: most weeks one return trip, none at
     * Christmas or in early August, plus trips on 5 and 6 April 2025.
     */
    private function trips(): void
    {
        $routes = [
            ['Leeds', 'Birmingham', 118], ['Leeds', 'Birmingham', 118], ['Leeds', 'Birmingham', 118],
            ['Leeds', 'Birmingham', 118], ['Leeds', 'Newcastle', 95], ['Leeds', 'London', 195], ['Leeds', 'Manchester', 44],
        ];
        $day = new DateTimeImmutable('2023-10-10');
        $end = new DateTimeImmutable(self::END);
        while ($day <= $end) {
            $md = $day->format('m-d');
            $off = ($md >= '12-20' || $md <= '01-03') || ($md >= '08-01' && $md <= '08-16');
            if (!$off) {
                [$from, $to, $miles] = $routes[$this->rng->between(0, count($routes) - 1)];
                $this->trip($day->modify('+' . $this->rng->between(0, 3) . ' days'), $from, $to, $miles, 'Client visit');
            }
            $day = $day->modify('+7 days');
        }
        $this->trip(new DateTimeImmutable('2025-04-05'), 'Leeds', 'York', 25, 'Saturday site visit (tax year 2024–25)');
        $this->trip(new DateTimeImmutable('2025-04-06'), 'Leeds', 'York', 25, 'Sunday site visit (tax year 2025–26)');
    }

    private function trip(DateTimeImmutable $on, string $from, string $to, int $milesOneWay, string $purpose): void
    {
        $miles = 2 * $milesOneWay;
        $this->add('trips', [
            'vehicle' => 'golf', 'user' => 'alex', 'date' => $on->format('Y-m-d'), 'from' => $from, 'to' => $to,
            'return' => 'yes', 'typed_miles_one_way' => (string) $milesOneWay,
            'distance_km' => self::fixed(self::milesToMetres($miles), 3), 'business' => 'yes', 'purpose' => $purpose,
        ]);
    }

    /**
     * EV: home charging on two tariffs, public AC and DC, a free charge, and
     * an HP agreement still running today, with one payment missed then paid
     * late.
     */
    private function ev(): void
    {
        $this->vehicle('ev', 'alex', [
            'type' => 'car', 'make' => 'Kia', 'model' => 'Niro EV', 'variant' => '4', 'fuel_type' => 'ev',
            'registration' => 'YC23 EVN', 'currency' => 'GBP', 'first_registered_on' => '2023-06-15',
            'purchase_date' => '2024-02-10', 'purchase_price' => '27500.00',
        ]);
        $this->reading('ev', 'alex', '2024-02-10 15:00', 6120000, 'Odometer when bought');
        $this->chargeRun();

        $this->add('finance', [
            'vehicle' => 'ev', 'agreement' => 'ev-hp', 'type' => 'hp', 'lender' => 'Greenline Finance',
            'started_on' => '2024-02-10', 'first_payment_on' => '2024-03-10', 'number_of_payments' => '48',
            'first_payment' => '', 'regular_payment' => '551.12', 'final_payment' => '', 'final_payment_on' => '',
            'cash_price' => '27500.00', 'customer_deposit' => '4000.00', 'dealer_contribution' => '0.00',
            'amount_of_credit' => '23500.00', 'apr' => '5.9', 'documentation_fee' => '', 'option_to_purchase_fee' => '',
            'annual_mileage_allowance' => '', 'mileage_unit' => 'mi', 'excess_mileage_charge' => '',
            'start_odometer_km' => '', 'count_in_costs' => 'yes',
        ]);
        $this->add('finance_events', [
            'vehicle' => 'ev', 'agreement' => 'ev-hp', 'kind' => 'missed', 'date' => '', 'due_on' => '2025-01-10',
            'amount' => '', 'note' => 'Direct debit failed',
        ]);
        $this->add('finance_events', [
            'vehicle' => 'ev', 'agreement' => 'ev-hp', 'kind' => 'paid_late', 'date' => '2025-01-24', 'due_on' => '2025-01-10',
            'amount' => '', 'note' => 'Paid by card',
        ]);

        foreach (
            [['2025-02-10', '22000.00', 'Online guide'], ['2026-02-10', '19250.00', 'Online guide']] as [$on, $amount,
            $source]
        ) {
            $this->add('valuations', ['vehicle' => 'ev', 'valued_on' => $on, 'amount' => $amount, 'source' => $source]);
        }
        $this->insurance('ev', 'Direct Line', '2024-02-10', ['804.20', '871.66', '845.03']);
        $this->maintenance('ev', '2025-02-21', 'service', 'Annual EV health check', '149.00', 'Kia Leeds');
        $this->maintenance('ev', '2026-02-26', 'service', 'Annual EV health check and cabin filter', '189.00', 'Kia Leeds');
        $this->maintenance('ev', '2025-10-03', 'tyres', 'Four tyres (EV rated)', '736.00', 'Kwik Fit');
        $this->document('ev', 'inspection', 'First MOT', 'Kwik Fit', '2026-06-12', '2027-06-11', '54.85');
        $this->schedule('ev', 'service', 'Annual EV health check', '', '12', '2026-02-26');
        // Vehicle tax: £0 for an EV until April 2025 (a £0 expense still counts), then the standard rate.
        $this->expense('ev', '2024-02-10', 'tax', '0.00', 'Vehicle tax (zero-rated EV)');
        $this->expense('ev', '2025-04-01', 'tax', '195.00', 'Vehicle tax');
        $this->expense('ev', '2026-04-01', 'tax', '195.00', 'Vehicle tax');
        $this->expense('ev', '2024-02-12', 'accessories', '42.99', 'Type 2 cable bag');
    }

    /**
     * A car sold during the window: a valuation on the purchase day (#389),
     * an accident with an insurance payout and an excess, sold above its last
     * valuation.
     */
    private function focus(): void
    {
        $this->vehicle('focus', 'alex', [
            'type' => 'car', 'make' => 'Ford', 'model' => 'Focus', 'variant' => '1.5 TDCi Zetec', 'fuel_type' => 'diesel',
            'registration' => 'YK16 FCS', 'currency' => 'GBP', 'first_registered_on' => '2016-05-20',
            'purchase_date' => '2023-09-20', 'purchase_price' => '6450.00',
        ]);
        $this->files['vehicles'][count($this->files['vehicles']) - 1]['sale_date'] = '2025-07-19';
        $this->files['vehicles'][count($this->files['vehicles']) - 1]['sale_price'] = '4350.00';
        $this->reading('focus', 'alex', '2023-09-20 11:00', 96300000, 'Odometer when bought');

        $this->fuelRun('focus', [
            'from' => '2023-09-21', 'to' => '2025-07-19', 'odometer' => 96300000, 'km_per_day' => 38, 'per_100' => [4800,
                5500], 'tank' => 52000, 'fuel' => 'diesel',
            'prices' => ['2023-09' => 1589, '2024-04' => 1539, '2024-10' => 1459, '2025-04' => 1449, '2025-07' => 1439],
            'stations' => ['Morrisons Leeds', 'Shell Leeds Road', 'Asda Killingbeck'],
            'partial_one_in' => 12, 'by_sam_one_in' => 0, 'forced' => [],
        ]);
        $this->reading(
            'focus',
            'alex',
            '2025-07-19 10:00',
            $this->odometerAt('focus', '2025-07-19', '10:00'),
            'Odometer at sale'
        );

        foreach (
            [['2023-09-20', '6200.00', 'Trade guide on the day of purchase'], ['2024-09-20', '5300.00', 'Online guide'],
            ['2025-05-01', '3900.00', 'Online guide']] as [$on, $amount, $source]
        ) {
            $this->add('valuations', ['vehicle' => 'focus', 'valued_on' => $on, 'amount' => $amount, 'source' => $source]);
        }
        $this->insurance('focus', 'Aviva', '2023-09-20', ['455.70', '518.22']);
        $this->document('focus', 'inspection', 'MOT', 'Halfords', '2024-05-14', '2025-05-13', '54.85');
        $this->document('focus', 'inspection', 'MOT', 'Halfords', '2025-05-12', '2026-05-11', '54.85');
        $this->maintenance('focus', '2024-05-13', 'repair', 'MOT advisory: rear brake hoses', '96.00', 'Halfords');
        $this->maintenance('focus', '2024-08-02', 'service', 'Interim service', '179.00', 'Local garage');
        $this->maintenance(
            'focus',
            '2024-12-02',
            'bodywork',
            'Accident repair: rear bumper and tailgate',
            '1430.00',
            'Leeds Body Shop'
        );
        $this->maintenance('focus', '2025-04-08', 'battery', 'New battery', '128.50', 'Halfords');
        foreach (['2023-09-20' => '35.00', '2024-09-01' => '35.00'] as $on => $amount) {
            $this->expense('focus', $on, 'tax', $amount, 'Vehicle tax');
        }
        $this->parking('focus', '2023-09-25', '2025-07-15', 1);

        $this->add('incidents', [
            'vehicle' => 'focus', 'occurred_on' => '2024-11-08', 'type' => 'collision', 'fault' => 'other_party',
            'claim_status' => 'settled', 'insurer' => 'Aviva', 'excess' => '250.00', 'payout' => '1180.00',
            'updated_on' => '2025-01-10', 'status' => 'closed', 'closed_on' => '2025-01-10',
            'note' => 'Rear-ended at lights; repair 2 Dec 2024 (maintenance)',
        ]);
    }

    /**
     * A car bought in euros and kept in Ireland by Sam: every figure stays in
     * euros, and a fill at 23:30 on 31 March 2024 (the day summer time
     * started) counts in March.
     */
    private function corolla(): void
    {
        $this->vehicle('corolla', 'sam', [
            'type' => 'car', 'make' => 'Toyota', 'model' => 'Corolla', 'variant' => '1.8 Hybrid Luna', 'fuel_type' => 'hybrid',
            'registration' => '211-D-48213', 'currency' => 'EUR', 'first_registered_on' => '2021-02-11',
            'purchase_date' => '2023-11-04', 'purchase_price' => '21400.00',
        ]);
        $this->reading('corolla', 'sam', '2023-11-04 14:30', 52700000, 'Odometer when bought');
        $this->fuelRun('corolla', [
            'from' => '2023-11-05', 'to' => self::END, 'odometer' => 52700000, 'km_per_day' => 44, 'per_100' => [4300, 5000],
                'tank' => 43000, 'fuel' => 'petrol',
            'prices' => ['2023-11' => 1799, '2024-06' => 1769, '2025-01' => 1739, '2025-08' => 1719, '2026-09' => 1729],
            'stations' => ['Circle K Swords', 'Applegreen Airside', 'Maxol Malahide'],
            'partial_one_in' => 10, 'by_sam_one_in' => 1,
            'forced' => ['2024-03-31 23:30' => 'sam'],
        ]);
        $this->add('valuations', ['vehicle' => 'corolla', 'valued_on' => '2025-11-01', 'amount' => '17200.00',
            'source' => 'Carzone guide']);
        $this->insurance('corolla', 'FBD', '2023-11-04', ['642.00', '688.50', '701.25']);
        $this->document('corolla', 'inspection', 'NCT', 'NCTS Northpoint', '2025-02-03', '2027-02-02', '55.00');
        $this->maintenance('corolla', '2024-11-12', 'service', 'Toyota service', '269.00', 'Toyota Swords');
        $this->maintenance('corolla', '2025-01-28', 'repair', 'NCT pre-check: wiper blades, bulb', '38.40', 'Toyota Swords');
        $this->maintenance('corolla', '2025-11-18', 'service', 'Toyota service', '284.00', 'Toyota Swords');
        $this->schedule('corolla', 'service', 'Toyota service', '15000.000', '12', '2025-11-18');
        foreach (['2023-11-04' => '190.00', '2024-11-01' => '190.00', '2025-11-01' => '200.00'] as $on => $amount) {
            $this->expense('corolla', $on, 'tax', $amount, 'Motor tax');
        }
        foreach (
            ['2024-02-18' => '3.10', '2024-09-07' => '3.10', '2025-05-22' => '3.20',
            '2026-03-30' => '3.20'] as $on => $amount
        ) {
            $this->expense('corolla', $on, 'tolls', $amount, 'M50 eFlow');
        }
        $this->parking('corolla', '2023-11-10', self::END, 1);
    }

    // ------------------------------------------------------------- runs

    /**
     * Fill-ups, simulated day by day: each day's driving uses fuel, and the
     * tank is filled when what it has used passes a level drawn afresh after
     * each full fill (55–85% of the tank). Now and then a fill is partial.
     *
     * @param array{from: string, to: string, odometer: int, km_per_day: int, per_100: array{int, int},
     *     tank: int, fuel: string, prices: array<string, int>, stations: list<string>,
     *     partial_one_in: int, by_sam_one_in: int, forced: array<string, string>} $run
     *     metres for odometers, millilitres per 100 km, tenths of a penny (or cent) per litre
     */
    private function fuelRun(string $vehicle, array $run): void
    {
        $odometer = $run['odometer'];
        $day = new DateTimeImmutable($run['from']);
        $end = new DateTimeImmutable($run['to']);
        $used = 0;
        $level = $this->level($run['tank']);
        while ($day <= $end) {
            $date = $day->format('Y-m-d');
            $metres = $this->drive($vehicle, $date, $odometer, $run['km_per_day']);
            $used += intdiv($metres * $this->rng->between($run['per_100'][0], $run['per_100'][1]), 100000);
            $forced = null;
            foreach ($run['forced'] as $at => $who) {
                if (str_starts_with($at, $date)) {
                    $forced = [substr($at, 11), $who];
                }
            }
            if ($forced !== null || ($used >= $level && $day < $end)) {
                $time = $forced[0] ?? sprintf('%02d:%02d', $this->rng->between(7, 20), $this->rng->between(0, 59));
                $by = $forced[1] ?? $this->driver($run['by_sam_one_in']);
                $partial = $forced === null && $used > 15000 && $run['partial_one_in'] > 0
                    && $this->rng->oneIn($run['partial_one_in']);
                $volume = $partial ? $this->rng->between(10000, max(10000, $used - 5000)) : $used;
                $used -= min($used, $volume);
                if (!$partial) {
                    $level = $this->level($run['tank']);
                }
                $this->fill(
                    $vehicle,
                    $by,
                    $date . ' ' . $time,
                    $this->odometerAt($vehicle, $date, $time),
                    $run['fuel'],
                    $volume,
                    $this->priceOn($run['prices'], $day) + $this->rng->between(-12, 12),
                    $partial,
                    $run['stations'][$this->rng->between(0, count($run['stations']) - 1)]
                );
            }
            $odometer += $metres;
            $day = $day->modify('+1 day');
        }
    }

    /**
     * The EV's charges: at home overnight when the battery needs it (full:
     * charged to the car's limit), public AC now and then, rapid DC on long
     * days, and a free supermarket charge.
     */
    private function chargeRun(): void
    {
        $odometer = 6120000;
        $day = new DateTimeImmutable('2024-02-11');
        $end = new DateTimeImmutable(self::END);
        $used = 0;
        while ($day <= $end) {
            $date = $day->format('Y-m-d');
            $metres = $this->drive('ev', $date, $odometer, 30);
            $used += intdiv($metres * $this->rng->between(16000, 19500), 100000);
            $public = null;
            if ($date === '2025-06-14') {
                $public = ['11:05', 12400, 0, 'Tesco Seacroft (free)'];
            } elseif ($used > 12000 && $this->rng->oneIn(16)) {
                $public = [$this->clock(10, 18), $this->rng->between(18000, min(40000, $used + 20000)), 790,
                    'Ionity Woodall (DC)'];
            } elseif ($used > 6000 && $this->rng->oneIn(20)) {
                $public = [$this->clock(9, 17), $this->rng->between(6000, 12000), 520, 'Leeds Council car park (AC)'];
            }
            if ($public !== null) {
                [$time, $volume, $price, $station] = $public;
                $used -= min($used, $volume);
                $this->fill(
                    'ev',
                    'alex',
                    $date . ' ' . $time,
                    $this->odometerAt('ev', $date, $time),
                    'ev',
                    $volume,
                    $price,
                    true,
                    $station
                );
            }
            if ($used >= 15000 || ($used > 7000 && $this->rng->oneIn(4))) {
                $time = sprintf('23:%02d', $this->rng->between(30, 59));
                $tariff = $date < '2025-04-01' ? 245 : 75;
                $this->fill(
                    'ev',
                    'alex',
                    $date . ' ' . $time,
                    $this->odometerAt('ev', $date, $time),
                    'ev',
                    $used,
                    $tariff,
                    false,
                    'Home'
                );
                $used = 0;
            }
            $odometer += $metres;
            $day = $day->modify('+1 day');
        }
    }

    /**
     * One day's driving, remembered so any time that day has an odometer.
     *
     * @return int metres driven
     */
    private function drive(string $vehicle, string $date, int $odometer, int $kmPerDay): int
    {
        $metres = intdiv($kmPerDay * 1000 * $this->rng->between(40, 160), 10000) * 100;
        $this->days[$vehicle][$date] = [$odometer, $metres];

        return $metres;
    }

    /**
     * The odometer at a local time on a simulated day: the day's driving is
     * spread evenly over its 24 hours, to the 100 m.
     */
    private function odometerAt(string $vehicle, string $date, string $time): int
    {
        [$start, $metres] = $this->days[$vehicle][$date] ?? throw new RuntimeException("No driving for {$vehicle} on {$date}");
        $minutes = (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        return $start + intdiv(intdiv($metres * $minutes, 1440), 100) * 100;
    }

    private function level(int $tank): int
    {
        return intdiv($tank * $this->rng->between(55, 85), 100);
    }

    private function driver(int $samOneIn): string
    {
        if ($samOneIn === 1) {
            return 'sam';
        }

        return $samOneIn > 1 && $this->rng->oneIn($samOneIn) ? 'sam' : 'alex';
    }

    private function clock(int $fromHour, int $toHour): string
    {
        return sprintf('%02d:%02d', $this->rng->between($fromHour, $toHour), $this->rng->between(0, 59));
    }

    /**
     * @param int $metres odometer in metres
     * @param int $volumeMl millilitres, or watt-hours for electricity
     * @param int $price tenths of a penny (or cent) per unit
     */
    private function fill(
        string $vehicle,
        string $by,
        string $at,
        int $metres,
        string $fuel,
        int $volumeMl,
        int $price,
        bool $partial,
        string $station
    ): void {
        // The pump's total: litres × price, to the penny, half up.
        $micro = $volumeMl * $price;
        $pence = intdiv($micro + 5000, 10000);
        $this->add('fillups', [
            'vehicle' => $vehicle, 'by' => $by, 'at' => $at, 'odometer_km' => self::fixed($metres, 3), 'fuel' => $fuel,
            'volume' => self::fixed($volumeMl, 3), 'price_per_unit' => self::fixed($price, 3),
            'total_cost' => self::fixed($pence, 2), 'partial' => $partial ? 'yes' : 'no', 'station' => $station,
        ]);
    }

    /**
     * @param array<string, int> $anchors price at the start of each listed month
     */
    private function priceOn(array $anchors, DateTimeImmutable $on): int
    {
        $months = static fn (string $ym): int => (int) substr($ym, 0, 4) * 12 + (int) substr($ym, 5, 2);
        $now = $months($on->format('Y-m'));
        $keys = array_keys($anchors);
        $previous = $keys[0];
        foreach ($keys as $key) {
            if ($months($key) >= $now) {
                $span = $months($key) - $months($previous);
                if ($span === 0) {
                    return $anchors[$key];
                }

                return $anchors[$previous] + intdiv(($anchors[$key] - $anchors[$previous]) * ($now - $months($previous)), $span);
            }
            $previous = $key;
        }

        return $anchors[$previous];
    }

    private function parking(string $vehicle, string $from, string $to, int $perMonth): void
    {
        $day = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($day <= $end) {
            for ($i = 0; $i < $perMonth; $i++) {
                $on = $day->modify('+' . $this->rng->between(0, 27) . ' days');
                if ($on <= $end) {
                    $this->expense($vehicle, $on->format('Y-m-d'), 'parking', self::fixed(
                        $this->rng->between(24, 98) * 10,
                        2
                    ), 'Parking');
                }
            }
            $day = $day->add(new DateInterval('P1M'));
        }
    }

    // ------------------------------------------------------------- records

    private function reading(string $vehicle, string $by, string $at, int $metres, string $note): void
    {
        $this->add('readings', [
            'vehicle' => $vehicle, 'by' => $by, 'at' => $at, 'odometer_km' => self::fixed($metres, 3), 'note' => $note,
        ]);
    }

    private function maintenance(
        string $vehicle,
        string $on,
        string $category,
        string $title,
        string $cost,
        string $vendor
    ): void {
        // Recorded at noon, so its odometer is the simulated one at 12:00.
        $this->add('maintenance', [
            'vehicle' => $vehicle, 'date' => $on, 'category' => $category, 'title' => $title, 'cost' => $cost,
            'odometer_km' => self::fixed($this->odometerAt($vehicle, $on, '12:00'), 3), 'vendor' => $vendor,
        ]);
    }

    private function document(
        string $vehicle,
        string $type,
        string $title,
        string $provider,
        string $start,
        string $expiry,
        string $cost
    ): void {
        $this->add('documents', [
            'vehicle' => $vehicle, 'type' => $type, 'title' => $title, 'provider' => $provider, 'start_on' => $start,
            'expiry_on' => $expiry, 'cost' => $cost,
        ]);
    }

    /**
     * Yearly insurance from $start, one premium a year.
     *
     * @param list<string> $premiums
     */
    private function insurance(string $vehicle, string $insurer, string $start, array $premiums): void
    {
        $from = new DateTimeImmutable($start);
        foreach ($premiums as $premium) {
            $this->document(
                $vehicle,
                'insurance',
                'Car insurance',
                $insurer,
                $from->format('Y-m-d'),
                $from->modify('+1 year -1 day')->format('Y-m-d'),
                $premium
            );
            $from = $from->modify('+1 year');
        }
    }

    private function expense(string $vehicle, string $on, string $category, string $amount, string $note): void
    {
        $this->add('expenses', ['vehicle' => $vehicle, 'date' => $on, 'category' => $category, 'amount' => $amount,
            'note' => $note]);
    }

    private function schedule(
        string $vehicle,
        string $category,
        string $title,
        string $intervalKm,
        string $intervalMonths,
        string $doneOn
    ): void {
        $this->add('schedules', [
            'vehicle' => $vehicle, 'category' => $category, 'title' => $title, 'interval_km' => $intervalKm,
            'interval_months' => $intervalMonths, 'baseline_done_on' => $doneOn,
            'baseline_done_km' => self::fixed($this->odometerAt($vehicle, $doneOn, '12:00'), 3),
        ]);
    }

    // ------------------------------------------------------------- numbers

    /** A whole number of thousandths (or hundredths) as a fixed-point string. */
    private static function fixed(int $units, int $places): string
    {
        $scale = 10 ** $places;
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return $sign . intdiv($units, $scale) . '.' . str_pad((string) ($units % $scale), $places, '0', STR_PAD_LEFT);
    }

    /** Miles to metres, exactly (1 mile = 1609.344 m), rounded half up to the metre. */
    private static function milesToMetres(int $miles): int
    {
        return intdiv($miles * 1609344 + 500, 1000);
    }

    /** The zone a user's wall-clock times are in. */
    public static function zoneOf(string $user): DateTimeZone
    {
        return new DateTimeZone(self::ZONES[$user]);
    }
}
