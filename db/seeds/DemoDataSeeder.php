<?php

declare(strict_types=1);

use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Kernel;
use Logbook\Service\Tyre\TyreReplay;
use Logbook\Service\Tyre\TyreReplayResult;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Phinx\Seed\AbstractSeed;

/**
 * Sample data for local development: a demo owner (an admin) and a small
 * garage (the vehicles from the design mock-ups, one of them sold and
 * archived), and a second user the owner shares two vehicles with (Phase
 * 19): Log without costs on the self-charging hybrid, whose fill-ups they
 * partly logged, and View on the Golf.
 *
 *   ./bin/dev-setup.sh --with-sample-data
 *   vendor/bin/phinx seed:run -e development -s DemoDataSeeder
 *
 * Sign in as `demo` / `logbook-demo`, or `partner` / `logbook-demo`. Refuses
 * to run in production, and on a database that already has an account:
 * reset first with `./bin/dev-setup.sh --reset`.
 */
final class DemoDataSeeder extends AbstractSeed
{
    public const string USERNAME = 'demo';
    public const string PASSWORD = 'logbook-demo';
    public const string PARTNER = 'partner';

    public function run(): void
    {
        if (Kernel::settings()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder is for development only (APP_ENV=production).');
        }

        $existing = $this->fetchRow('SELECT COUNT(*) AS n FROM users');
        if (is_array($existing) && self::intValue($existing['n'] ?? $existing[0] ?? 0) > 0) {
            $this->getOutput()->writeln(
                '<comment>An account already exists; sample data not added. '
                . 'Reset the database first (./bin/dev-setup.sh --reset --with-sample-data).</comment>',
            );

            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->table('users')->insert([
            'username' => self::USERNAME,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            'display_name' => 'Demo Driver',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
            'distance_unit' => 'mi',
            'volume_unit' => 'l',
            'consumption_unit' => 'mpg_uk',
            'currency' => 'GBP',
            'theme' => 'system',
            'is_admin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->saveData();

        $user = $this->fetchRow("SELECT id FROM users WHERE username = '" . self::USERNAME . "'");
        if (!is_array($user)) {
            throw new RuntimeException('Demo user was not created.');
        }
        $userId = self::intValue($user['id'] ?? $user[0] ?? null);

        // Every row gets every column in the same order (Phinx bulk-inserts by position).
        $vehicle = static fn (array $values): array => array_merge([
            'user_id' => $userId,
            'type' => 'car',
            'make' => '',
            'model' => '',
            'fuel_type' => 'petrol',
            'default_grade' => null,
            'nickname' => null,
            'year' => null,
            'first_registered_on' => null,
            'first_inspection_due_on' => null,
            'registration' => null,
            'vin' => null,
            'capacity' => null,
            'currency' => null,
            'photo_path' => null,
            'photo_mime' => null,
            'purchase_date' => null,
            'purchase_price' => null,
            'sale_date' => null,
            'sale_price' => null,
            'status' => 'active',
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);

        $this->table('vehicles')->insert([
            $vehicle([
                'type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf 1.5 TSI Life', 'year' => 2019,
                'registration' => 'LB19 KTR', 'vin' => 'WVWZZZCDZKW123456', 'fuel_type' => 'petrol',
                'default_grade' => 'e10_95', 'capacity' => '50.000',
                'purchase_date' => '2021-03-14', 'purchase_price' => '14250.000',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Toyota', 'model' => 'Corolla 1.8 Hybrid', 'year' => 2022,
                'registration' => 'LK22 VXN', 'fuel_type' => 'hybrid', 'capacity' => '43.000',
                'purchase_date' => '2022-09-01', 'purchase_price' => '24995.000',
            ]),
            $vehicle([
                'type' => 'bike', 'nickname' => 'Street Triple', 'make' => 'Triumph', 'model' => 'Street Triple R',
                'year' => 2020, 'registration' => 'MT20 BKE', 'fuel_type' => 'petrol', 'capacity' => '15.000',
                'purchase_date' => '2023-04-22', 'purchase_price' => '7800.000',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Kia', 'model' => 'EV6 GT-Line', 'year' => 2023,
                'registration' => 'EV23 KIA', 'fuel_type' => 'ev', 'default_grade' => 'home',
                'capacity' => '77.400', 'currency' => 'EUR',
                // Leased: no purchase price, so its cost of ownership is its running costs, lease included.
                'purchase_date' => '2024-02-10', 'purchase_price' => null,
                // Leased new, so no MOT certificate yet: its first MOT is 3 years on (spec.md §7.1, Phase 21.2).
                'first_registered_on' => '2024-02-09', 'first_inspection_due_on' => '2027-02-09',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Ford', 'model' => 'Fiesta 1.0 EcoBoost', 'year' => 2014,
                'registration' => 'WR14 FNE', 'fuel_type' => 'petrol', 'capacity' => '42.000',
                'purchase_date' => '2016-06-30', 'purchase_price' => '6500.000',
                'sale_date' => '2025-11-20', 'sale_price' => '2100.000',
                'status' => 'archived', 'archived_at' => '2025-11-20 12:00:00',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Mitsubishi', 'model' => 'Outlander 2.4 PHEV', 'year' => 2021,
                'registration' => 'YR21 PHV', 'fuel_type' => 'phev', 'default_grade' => 'e10_95', 'capacity' => '45.000',
                'purchase_date' => '2025-12-05', 'purchase_price' => '21450.000',
            ]),
        ])->saveData();

        $this->seedFuel($now);
        $this->seedMaintenance($now);
        $this->seedDocuments($now);
        $this->seedReminders($now);
        $this->seedExpenses($now);
        $this->seedFiestaLifetime($now);
        $this->seedTyres($now);
        $this->seedPaperwork($now);
        $this->seedValuations($now);
        $this->seedSalePack($now);
        $this->seedTrips($now, $userId);
        $this->seedPartner($now, $userId);

        $this->getOutput()->writeln(sprintf(
            '<info>Sample data added. Sign in as "%s" (or "%s") with password "%s".</info>',
            self::USERNAME,
            self::PARTNER,
            self::PASSWORD,
        ));
    }

    /**
     * Who added what (Phase 19): the owner everything, then a second user,
     * a member, with Log access to the hybrid (no costs) and View access to
     * the Golf, who fills the hybrid up in the middle of each of the last
     * six months; the owner fills it on the 10th and 20th. On E10 95 it
     * uses about 4.1 L/100 km; from 10 Jul it is E5 98 and about 12% more,
     * a slow drift over the last five tanks (Phase 25) with no single tank
     * unusual.
     */
    private function seedPartner(string $now, int $ownerId): void
    {
        $authored = [
            'fuel_entries', 'maintenance_entries', 'compliance_documents',
            'expense_entries', 'tyre_changes', 'vehicle_valuations',
        ];
        foreach ($authored as $table) {
            $this->execute(sprintf('UPDATE %s SET created_by = %d WHERE created_by IS NULL', $table, $ownerId));
        }
        $this->execute(sprintf(
            "UPDATE odometer_readings SET created_by = %d WHERE created_by IS NULL AND source = 'manual'",
            $ownerId,
        ));
        $this->execute(sprintf('UPDATE attachments SET uploaded_by = %d WHERE uploaded_by IS NULL', $ownerId));

        $this->table('users')->insert([
            'username' => self::PARTNER,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            'display_name' => 'Sam Partner',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
            'distance_unit' => 'km',
            'volume_unit' => 'l',
            'consumption_unit' => 'l_per_100km',
            'currency' => 'GBP',
            'theme' => 'system',
            'is_admin' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->saveData();
        $partner = $this->fetchRow("SELECT id FROM users WHERE username = '" . self::PARTNER . "'");
        if (!is_array($partner)) {
            throw new RuntimeException('Demo partner was not created.');
        }
        $partnerId = self::intValue($partner['id'] ?? $partner[0] ?? null);

        $ids = $this->vehicleIds();
        $hybrid = $ids['LK22 VXN'] ?? throw new RuntimeException('The demo hybrid is missing.');
        $golf = $ids['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        $share = static fn (int $vehicle, string $level): array => [
            'vehicle_id' => $vehicle,
            'user_id' => $partnerId,
            'level' => $level,
            'can_see_costs' => false,
            'notify' => $level === 'log',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->table('vehicle_shares')->insert([$share($hybrid, 'log'), $share($golf, 'view')])->saveData();

        // The partner fills the hybrid up mid-month, between the owner's
        // monthly readings; the owner twice a month before that.
        $readings = array_values(array_filter(
            $this->fetchAll(sprintf(
                "SELECT reading_km, recorded_at FROM odometer_readings"
                    . " WHERE vehicle_id = %d AND source = 'manual' ORDER BY recorded_at",
                $hybrid,
            )),
            is_array(...),
        ));
        $fill = static fn (string $at, float $km, float $litres, float $price, string $grade, int $by): array => [
            'vehicle_id' => $hybrid,
            'filled_at' => $at,
            'odometer_km' => number_format($km, 3, '.', ''),
            'fuel' => 'petrol',
            'grade' => $grade,
            'volume' => number_format($litres, 3, '.', ''),
            'price_per_unit' => number_format($price, 6, '.', ''),
            'total_cost' => number_format(round($litres * $price, 2), 3, '.', ''),
            'is_partial' => false,
            'is_missed_previous' => false,
            'station' => 'Tesco Extra',
            'notes' => null,
            'created_by' => $by,
            'created_at' => $at,
            'updated_at' => $at,
        ];
        // Owner on the 10th and 20th, partner on the 15th of the last six
        // months, each between the monthly readings. From 10 Jul the tank is
        // E5 98 and it uses 12% more: the last five tanks drift (Phase 25).
        $first = max(1, count($readings) - 6);
        $planned = [];
        for ($i = 1; $i < count($readings); $i++) {
            $from = self::floatValue($readings[$i - 1]['reading_km'] ?? null);
            $to = self::floatValue($readings[$i]['reading_km'] ?? null);
            $recorded = $readings[$i - 1]['recorded_at'] ?? null;
            $month = substr(is_string($recorded) ? $recorded : '', 0, 10);
            foreach ([[10, 0.3, $ownerId], [15, 0.5, $partnerId], [20, 0.7, $ownerId]] as [$day, $share, $by]) {
                if ($by === $partnerId && $i < $first) {
                    continue;
                }
                $time = $by === $partnerId ? '17:30' : '08:15';
                $at = gmdate('Y-m-d H:i:s', (int) strtotime($month . ' +' . ($day - 1) . ' days ' . $time));
                $planned[] = [$at, $from + ($to - $from) * $share, $by, $i + $day];
            }
        }
        $switch = '2026-07-10';
        $owners = [];
        $fills = [];
        $previous = null;
        $grade = 'e10_95';
        foreach ($planned as [$at, $km, $by, $n]) {
            // What this fill tops up was burned on the grade bought before it.
            $rate = $grade === 'e5_98' ? 0.046 : 0.041;
            $litres = $previous === null ? 36.0 : ($km - $previous) * $rate * (1 + ($n % 3 - 1) * 0.02);
            $grade = $at >= $switch ? 'e5_98' : 'e10_95';
            $price = ($grade === 'e5_98' ? 1.589 : 1.429) + ($n % 3) * 0.01;
            $row = $fill($at, $km, $litres, $price, $grade, $by);
            if ($by === $partnerId) {
                $fills[] = $row;
            } else {
                $owners[] = $row;
            }
            $previous = $km;
        }
        $this->table('fuel_entries')->insert([...$owners, ...$fills])->saveData();
        $this->execute(sprintf(
            'INSERT INTO odometer_readings'
            . ' (vehicle_id, reading_km, recorded_at, source, note, fuel_entry_id, created_at, updated_at)'
            . " SELECT vehicle_id, odometer_km, filled_at, 'fuel', NULL, id, created_at, updated_at FROM fuel_entries"
            . ' WHERE vehicle_id = %d AND created_by = %d',
            $hybrid,
            $ownerId,
        ));
        $this->execute(sprintf(
            'INSERT INTO odometer_readings'
            . ' (vehicle_id, reading_km, recorded_at, source, note, fuel_entry_id, created_at, updated_at)'
            . " SELECT vehicle_id, odometer_km, filled_at, 'fuel', NULL, id, created_at, updated_at FROM fuel_entries"
            . ' WHERE created_by = %d',
            $partnerId,
        ));
    }

    /**
     * A year of fill-ups (with partial fills, one missed fill-up and EV
     * charges), each with its odometer reading, plus manual readings for the
     * self-charging hybrid, which is never charged. Deterministic, so every reset looks the same.
     */
    private function seedFuel(string $now): void
    {
        mt_srand(20260927);
        $ids = [];
        foreach ($this->fetchAll('SELECT id, registration FROM vehicles') as $row) {
            if (is_array($row) && is_string($row['registration'] ?? null)) {
                $ids[$row['registration']] = self::intValue($row['id'] ?? null);
            }
        }

        // Golf: ~45 mpg (UK), fill every ~12 days; partial every 6th; one fill-up never logged.
        // Grades from the 11th fill on (older ones predate grades, the snowy January tank among
        // them): mostly E10, with E5 97 in between, close enough together for a grade verdict
        // (E5 97 uses 3% less fuel and costs 12p a litre more). Winter costs about 7% more fuel
        // (Economy by month).
        [$golf, $confirmed] = self::economyChecks($this->fillUps(
            $ids['LB19 KTR'],
            '2025-09-20',
            30,
            61155.0,
            540.0,
            15.9,
            1.479,
            'petrol',
            6,
            17,
            $now,
            // In this order both tanks of the odometer typo are E10, so it cannot skew the verdict.
            ['e10_95', 'e5_97', 'e10_95', 'e5_97', 'e10_95'],
            10,
            noise: 0.03,
            winter: 0.07,
            burnedFactor: ['e5_97' => 0.97],
        ));

        $entries = [
            ...$golf,
            // The bike always takes super unleaded.
            ...$this->fillUps($ids['MT20 BKE'], '2026-03-15', 12, 18500.0, 230.0, 19.5, 1.529, 'petrol', 0, null, $now, grades: [
                'e5_98',
            ]),
            // EV6: charges in kWh, ~5.6 km/kWh, most of them partial; mostly at home, some rapid, one free.
            ...$this->fillUps(
                $ids['EV23 KIA'],
                '2026-01-05',
                24,
                21000.0,
                260.0,
                5.6,
                0.285,
                'ev',
                -3,
                null,
                $now,
                ['home', 'home', 'home', 'dc_rapid', 'home', 'home', 'ac', 'home'],
            ),
            ...$this->plugInHybrid($ids['YR21 PHV'], $now),
        ];
        $this->table('fuel_entries')->insert($entries)->saveData();
        $updated = $this->execute(
            'UPDATE fuel_entries SET economy_confirmed = ? WHERE vehicle_id = ? AND filled_at = ?',
            [$confirmed['consumption'], $ids['LB19 KTR'], $confirmed['filled_at']],
        );
        if ($updated !== 1) {
            throw new LogicException('The demo economy confirmation matched no single fill-up.');
        }

        // Phase 25: one of the Golf's recent full E10 tanks with the price
        // typed ten times over (14.79 for 1.479), for the price check: the
        // latest with at least three E10 fill-ups within 30 days to judge it by.
        $e10 = [];
        foreach (
            $this->fetchAll(sprintf(
                "SELECT id, filled_at, price_per_unit, is_partial FROM fuel_entries WHERE vehicle_id = %d AND grade = 'e10_95'",
                $ids['LB19 KTR'],
            )) as $row
        ) {
            if (is_array($row)) {
                $e10[] = $row + ['time' => (int) strtotime(self::stringValue($row['filled_at'] ?? null) . ' UTC')];
            }
        }
        usort($e10, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        $typo = null;
        foreach ($e10 as $candidate) {
            // Booleans come back as 0/1, "0"/"1" or t/f depending on the engine.
            if (in_array($candidate['is_partial'] ?? null, [true, 1, '1', 't'], true)) {
                continue;
            }
            $near = array_filter($e10, static fn (array $o): bool => $o['id'] !== $candidate['id']
                && abs($o['time'] - $candidate['time']) <= 30 * 86400);
            if (count($near) >= 3) {
                $typo = $candidate;
                break;
            }
        }
        if ($typo === null) {
            throw new LogicException('No demo Golf fill-up can be judged for the price check.');
        }
        $this->execute(
            'UPDATE fuel_entries SET price_per_unit = ? WHERE id = ?',
            [
                Decimal::multiply(number_format(self::floatValue($typo['price_per_unit'] ?? null), 6, '.', ''), '10', 6),
                self::intValue($typo['id'] ?? null),
            ],
        );

        // Each fill-up's odometer reading (portable INSERT … SELECT).
        $this->execute(
            'INSERT INTO odometer_readings'
            . ' (vehicle_id, reading_km, recorded_at, source, note, fuel_entry_id, created_at, updated_at)'
            . " SELECT vehicle_id, odometer_km, filled_at, 'fuel', NULL, id, created_at, updated_at FROM fuel_entries",
        );

        $readings = [];
        $km = 30500.0;
        for ($month = 0; $month < 12; $month++) {
            $km += 900 + mt_rand(0, 500);
            $readings[] = [
                'vehicle_id' => $ids['LK22 VXN'],
                'reading_km' => number_format($km, 3, '.', ''),
                'recorded_at' => gmdate('Y-m-d H:i:s', (int) strtotime(sprintf('2025-10-01 +%d months 09:00', $month))),
                'source' => 'manual',
                'note' => $month === 5 ? 'MOT' : null,
                'fuel_entry_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->table('odometer_readings')->insert($readings)->saveData();
    }

    /**
     * Schedules (one due soon, one on track, one by distance only) and a
     * service history, including a DIY job that cost nothing. The computed
     * last-done / next-due columns are written as the app would compute them.
     */
    private function seedMaintenance(string $now): void
    {
        $ids = $this->vehicleIds();
        $golf = $ids['LB19 KTR'];
        $bike = $ids['MT20 BKE'];

        $schedule = static fn (array $values): array => array_merge([
            'vehicle_id' => $golf,
            'category' => 'service',
            'title' => '',
            'interval_km' => null,
            'interval_months' => null,
            'baseline_done_on' => null,
            'baseline_done_km' => null,
            'last_done_on' => null,
            'last_done_km' => null,
            'next_due_on' => null,
            'next_due_km' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);
        $this->table('maintenance_schedules')->insert([
            // Every 10,000 mi or 12 months; last done by the entry below.
            $schedule([
                'title' => 'Annual service', 'interval_km' => '16093.440', 'interval_months' => 12,
                'baseline_done_on' => '2024-10-01', 'baseline_done_km' => '55000.000',
                'last_done_on' => '2025-10-02', 'last_done_km' => '62100.000',
                'next_due_on' => '2026-10-02', 'next_due_km' => '78193.440',
            ]),
            $schedule([
                'category' => 'brakes', 'title' => 'Brake fluid', 'interval_months' => 24,
                'baseline_done_on' => '2024-11-15', 'last_done_on' => '2024-11-15', 'next_due_on' => '2026-11-15',
            ]),
            // Every 500 mi, by distance only.
            $schedule([
                'vehicle_id' => $bike, 'category' => 'oil', 'title' => 'Clean and lube the chain', 'interval_km' => '804.672',
                'baseline_done_km' => '20600.000', 'last_done_km' => '20600.000', 'next_due_km' => '21404.672',
            ]),
            // Phase 24: the bike's yearly service is overdue, so *Needs attention* has a Now item.
            $schedule([
                'vehicle_id' => $bike, 'title' => 'Annual service', 'interval_months' => 12,
                'baseline_done_on' => '2025-08-20', 'last_done_on' => '2025-08-20', 'next_due_on' => '2026-08-20',
            ]),
        ])->saveData();

        $serviceId = 0;
        foreach ($this->fetchAll('SELECT id, vehicle_id, title FROM maintenance_schedules') as $row) {
            if (
                is_array($row)
                && ($row['title'] ?? null) === 'Annual service'
                && self::intValue($row['vehicle_id'] ?? null) === $golf
            ) {
                $serviceId = self::intValue($row['id'] ?? null);
            }
        }

        $entry = static fn (array $values): array => array_merge([
            'vehicle_id' => $golf,
            'schedule_id' => null,
            'performed_on' => '',
            'odometer_km' => null,
            'category' => 'service',
            'title' => '',
            'description' => null,
            'cost' => '0.000',
            'vendor' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);
        $this->table('maintenance_entries')->insert([
            // The Golf's service history since it was bought (the sale pack's *Service and repairs*).
            $entry([
                'performed_on' => '2022-03-20', 'odometer_km' => '38900.000', 'title' => 'Annual service',
                'description' => 'Oil and filter, brake fluid.', 'cost' => '165.000', 'vendor' => 'Main Street Motors',
            ]),
            $entry([
                'performed_on' => '2023-03-18', 'odometer_km' => '45600.000', 'title' => 'Annual service',
                'description' => 'Oil and filter, air filter, spark plugs.', 'cost' => '239.000',
                'vendor' => 'Main Street Motors',
            ]),
            $entry([
                'performed_on' => '2024-10-01', 'odometer_km' => '55000.000', 'title' => 'Annual service',
                'description' => 'Oil and filter, pollen filter.', 'cost' => '178.000', 'vendor' => 'Main Street Motors',
            ]),
            $entry([
                'schedule_id' => $serviceId, 'performed_on' => '2025-10-02', 'odometer_km' => '62100.000',
                'title' => 'Annual service', 'description' => 'Oil and filter, air filter, pollen filter.',
                'cost' => '189.000', 'vendor' => 'Main Street Motors',
            ]),
            $entry(['performed_on' => '2026-01-20', 'category' => 'other', 'title' => 'Wiper blades (DIY)']),
            $entry([
                'performed_on' => '2026-03-10', 'odometer_km' => '69800.000', 'category' => 'tyres',
                'title' => 'Two front tyres', 'cost' => '176.000', 'vendor' => 'Kwik Fit',
            ]),
            $entry([
                'performed_on' => '2026-06-18', 'odometer_km' => '74050.000', 'category' => 'brakes',
                'title' => 'Front brake pads', 'cost' => '95.500', 'vendor' => 'Main Street Motors',
            ]),
            // Phase 25: £178 typed as £1,780, for the cost check.
            $entry([
                'performed_on' => '2026-04-14', 'title' => 'Interim service',
                'description' => 'Oil and filter.', 'cost' => '1780.000', 'vendor' => 'Main Street Motors',
            ]),
        ])->saveData();

        // Each entry's odometer reading, at noon (UK summer/winter time) on its date.
        $readings = [];
        foreach ($this->fetchAll('SELECT id, vehicle_id, performed_on, odometer_km FROM maintenance_entries') as $row) {
            if (!is_array($row) || $row['odometer_km'] === null || !is_string($row['performed_on'])) {
                continue;
            }
            $noon = new DateTimeImmutable(substr($row['performed_on'], 0, 10) . ' 12:00', new DateTimeZone('Europe/London'));
            $readings[] = [
                'vehicle_id' => self::intValue($row['vehicle_id']),
                'reading_km' => $row['odometer_km'],
                'recorded_at' => $noon->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'source' => 'maintenance',
                'maintenance_entry_id' => self::intValue($row['id']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->table('odometer_readings')->insert($readings)->saveData();
    }

    /**
     * Insurance (one renewal due soon, with last year's policy replaced),
     * an inspection, and a registration that never expires.
     */
    private function seedDocuments(string $now): void
    {
        $ids = $this->vehicleIds();

        $document = static fn (array $values): array => array_merge([
            'vehicle_id' => $ids['LB19 KTR'],
            'type' => 'insurance',
            'title' => null,
            'provider' => null,
            'reference' => null,
            'start_on' => null,
            'expiry_on' => null,
            'cost' => '0.000',
            'notes' => null,
            'odometer_km' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);
        $this->table('compliance_documents')->insert([
            $document([
                'provider' => 'Admiral', 'reference' => 'P-88213901', 'start_on' => '2024-10-10',
                'expiry_on' => '2025-10-09', 'cost' => '389.000',
            ]),
            $document([
                'provider' => 'Admiral', 'reference' => 'P-88213901', 'start_on' => '2025-10-10',
                'expiry_on' => '2026-10-09', 'cost' => '412.500', 'notes' => 'Fully comprehensive, protected NCD.',
            ]),
            // Last year's MOT and this year's, each with the mileage on the certificate.
            $document([
                'type' => 'inspection', 'provider' => 'Main Street Motors', 'reference' => '4403 1192 6650',
                'start_on' => '2025-03-06', 'expiry_on' => '2026-03-05', 'cost' => '54.850', 'odometer_km' => '57800.000',
            ]),
            $document([
                'type' => 'inspection', 'provider' => 'Main Street Motors', 'reference' => '5512 8830 1127',
                'start_on' => '2026-03-05', 'expiry_on' => '2027-03-04', 'cost' => '54.850', 'odometer_km' => '69050.000',
            ]),
            $document(['type' => 'registration', 'title' => 'V5C logbook', 'reference' => 'DVLA 4421 90871']),
            $document([
                'vehicle_id' => $ids['MT20 BKE'], 'provider' => 'Bennetts', 'start_on' => '2026-04-22',
                'expiry_on' => '2027-04-21', 'cost' => '189.000',
            ]),
            $document([
                'vehicle_id' => $ids['EV23 KIA'], 'provider' => 'Allianz', 'start_on' => '2026-02-10',
                'expiry_on' => '2027-02-09', 'cost' => '640.000',
            ]),
        ])->saveData();

        // A document's odometer joins the mileage series, at noon on its start date (spec.md §7.5).
        $readings = [];
        foreach ($this->fetchAll('SELECT id, vehicle_id, start_on, odometer_km FROM compliance_documents') as $row) {
            if (!is_array($row) || $row['odometer_km'] === null || !is_string($row['start_on'])) {
                continue;
            }
            $readings[] = [
                'vehicle_id' => self::intValue($row['vehicle_id']),
                'reading_km' => $row['odometer_km'],
                'recorded_at' => self::localNoon(substr($row['start_on'], 0, 10)),
                'source' => 'document',
                'compliance_document_id' => self::intValue($row['id']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->table('odometer_readings')->insert($readings)->saveData();
    }

    /**
     * A reminder of your own. Schedules and documents raise theirs on the
     * first sync (opening Reminders, or the scheduled task).
     */
    private function seedReminders(string $now): void
    {
        $this->table('reminders')->insert([
            [
                'vehicle_id' => $this->vehicleIds()['LB19 KTR'],
                'source' => 'manual',
                'title' => 'Winter tyres on',
                'notes' => 'Winter wheels are at Kwik Fit Southend, ref 4471.',
                'due_on' => '2026-11-01',
                'lead_time_days' => 14,
                'status' => 'upcoming',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    /**
     * Ad-hoc expenses; fuel, maintenance and document costs roll up by themselves.
     */
    private function seedExpenses(string $now): void
    {
        $ids = $this->vehicleIds();
        $expense = static fn (string $registration, string $date, string $category, string $amount, ?string $note): array => [
            'vehicle_id' => $ids[$registration],
            'spent_on' => $date,
            'category' => $category,
            'amount' => $amount,
            'note' => $note,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->table('expense_entries')->insert([
            $expense('LB19 KTR', '2026-08-14', 'parking', '18.500', 'Leeds station'),
            $expense('LB19 KTR', '2026-07-02', 'cleaning', '12.000', 'Car wash'),
            $expense('LB19 KTR', '2026-04-01', 'tax', '190.000', 'Vehicle excise duty'),
            $expense('LB19 KTR', '2025-12-20', 'tolls', '2.500', 'Dartford Crossing'),
            $expense('LB19 KTR', '2026-09-06', 'parking', '0.000', 'Free after 6pm'),
            $expense('MT20 BKE', '2026-05-11', 'accessories', '64.990', 'Tank bag'),
            $expense('EV23 KIA', '2026-06-18', 'tolls', '9.800', 'Péage A26'),
            ...$this->leasePayments($expense),
        ])->saveData();
    }

    /**
     * The sold Fiesta's sale receipt (spec.md §7.12): a one-page PDF written
     * under UPLOAD_PATH, shown on its *Sold* milestone.
     */
    /**
     * The leased Kia's monthly payments (the `finance` category), from the
     * month after the lease started to this month (spec.md §7.7 *Cost of
     * ownership*).
     *
     * @param callable(string, string, string, string, ?string): array<string, mixed> $expense
     * @return list<array<string, mixed>>
     */
    private function leasePayments(callable $expense): array
    {
        $rows = [];
        $month = new DateTimeImmutable('2024-03-10');
        $last = new DateTimeImmutable('2026-09-10');
        while ($month <= $last) {
            $rows[] = $expense('EV23 KIA', $month->format('Y-m-d'), 'finance', '449.000', 'Lease payment');
            $month = $month->modify('+1 month');
        }

        return $rows;
    }

    /**
     * The sold Fiesta's years with its owner, so its cost of ownership is an
     * exact lifetime figure (spec.md §7.7): the mileage at purchase and at
     * sale, a yearly service and road tax.
     */
    private function seedFiestaLifetime(string $now): void
    {
        $fiesta = $this->vehicleIds()['WR14 FNE'];
        $reading = static fn (string $date, string $km, string $note): array => [
            'vehicle_id' => $fiesta,
            'reading_km' => $km,
            'recorded_at' => self::localNoon($date),
            'source' => 'manual',
            'note' => $note,
            'fuel_entry_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->table('odometer_readings')->insert([
            $reading('2016-06-30', '38400.000', 'Bought'),
            $reading('2025-11-20', '131900.000', 'Sold'),
        ])->saveData();

        $services = [];
        $tax = [];
        for ($year = 2017; $year <= 2025; $year++) {
            $services[] = [
                'vehicle_id' => $fiesta, 'schedule_id' => null, 'performed_on' => sprintf('%d-06-15', $year),
                'odometer_km' => null, 'category' => 'service', 'title' => 'Annual service', 'description' => null,
                'cost' => $year % 2 === 0 ? '289.000' : '189.000', 'vendor' => 'Ford Main Dealer',
                'created_at' => $now, 'updated_at' => $now,
            ];
            $tax[] = [
                'vehicle_id' => $fiesta, 'spent_on' => sprintf('%d-07-01', $year), 'category' => 'tax',
                'amount' => '30.000', 'note' => 'Vehicle excise duty', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $this->table('maintenance_entries')->insert($services)->saveData();
        $this->table('expense_entries')->insert($tax)->saveData();
    }

    private function seedPaperwork(string $now): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF\n";
        $directory = Kernel::settings()->uploadPath . '/attachments';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample paperwork skipped.</comment>');

            return;
        }
        $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents(Kernel::settings()->uploadPath . '/' . $stored, $pdf);

        $fiesta = $this->vehicleIds()['WR14 FNE'];
        $this->table('attachments')->insert([
            'vehicle_id' => $fiesta,
            'owner_type' => 'sale',
            'owner_id' => $fiesta,
            'filename' => 'Sale receipt WR14 FNE.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($pdf),
            'stored_path' => $stored,
            'uploaded_at' => $now,
        ])->saveData();
    }

    /**
     * Valuations (spec.md §7.1): the Golf has a part-exchange offer and an
     * online valuation a year apart (the latest with a screenshot), so its
     * *Ownership* card shows depreciation and a value chart; the sold Fiesta
     * has one valuation before its sale, which its sale price overrides.
     * The Corolla's only valuation is 18 months old: a stale value, on its
     * Ownership card and in *Needs attention* (Phase 24).
     */
    private function seedValuations(string $now): void
    {
        $ids = $this->vehicleIds();
        $golf = $ids['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        $fiesta = $ids['WR14 FNE'] ?? throw new RuntimeException('The demo Fiesta is missing.');
        $corolla = $ids['LK22 VXN'] ?? throw new RuntimeException('The demo Corolla is missing.');
        $valuation = static fn (int $vehicle, string $on, string $amount, string $source, ?string $notes = null): array => [
            'vehicle_id' => $vehicle,
            'valued_on' => $on,
            'amount' => $amount,
            'source' => $source,
            'notes' => $notes,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->table('vehicle_valuations')->insert([
            $valuation($golf, '2025-03-08', '11200.000', 'Part-exchange offer, Arnold Clark'),
            $valuation($golf, '2026-03-14', '9800.000', 'Auto Trader valuation', 'Online, private sale, good condition'),
            $valuation($fiesta, '2025-10-02', '2300.000', 'We Buy Any Car online valuation'),
            $valuation($corolla, '2025-03-28', '19500.000', 'Part-exchange offer, Toyota dealer'),
        ])->saveData();

        $latest = null;
        foreach ($this->fetchAll('SELECT id, vehicle_id, valued_on FROM vehicle_valuations') as $row) {
            if (
                is_array($row)
                && self::intValue($row['vehicle_id'] ?? null) === $golf
                && is_string($row['valued_on'] ?? null)
                && str_starts_with($row['valued_on'], '2026-03-14')
            ) {
                $latest = self::intValue($row['id'] ?? null);
            }
        }
        if ($latest === null) {
            throw new RuntimeException('The demo Golf valuation was not created.');
        }

        // A 1×1 PNG stands in for the screenshot of the quote.
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );
        $directory = Kernel::settings()->uploadPath . '/attachments';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample valuation screenshot skipped.</comment>');

            return;
        }
        $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents(Kernel::settings()->uploadPath . '/' . $stored, $png);
        $this->table('attachments')->insert([
            'vehicle_id' => $golf,
            'owner_type' => 'valuation',
            'owner_id' => $latest,
            'filename' => 'Auto Trader valuation.png',
            'mime' => 'image/png',
            'size' => strlen($png),
            'stored_path' => $stored,
            'uploaded_at' => $now,
        ])->saveData();
    }

    /**
     * Trips (Phase 22, spec.md §7.22, §7.23): the trips module switched on,
     * three saved journeys, HMRC's rates with an employer paying 35p, and
     * about sixty business trips on the Golf across 2025/26 and 2026/27, so
     * the 45p → 55p change on 6 April 2026 shows in the claim. A few carry
     * passengers, two are private (listed, never in the split) and one has
     * its toll receipt.
     */
    private function seedTrips(string $now, int $userId): void
    {
        $golf = $this->vehicleIds()['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        $mile = 1.609344;
        $km = static fn (float $miles): string => number_format($miles * $mile, 3, '.', '');

        // Every module on, trips included (off by default).
        $this->table('settings')->insert([
            [
                'scope' => 'global',
                'owner_id' => 0,
                'name' => 'features',
                'value' => json_encode([
                    'fuel' => true, 'maintenance' => true, 'compliance' => true, 'reminders' => true,
                    'reports' => true, 'tyres' => true, 'trips' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scope' => 'user',
                'owner_id' => $userId,
                'name' => 'trips',
                'value' => json_encode([
                    'tax_year_start' => '04-06',
                    'declaration' => 'I confirm these journeys were made wholly for business.',
                    'rates_provided' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();

        $rates = static fn (string $from, string $car): array => [
            'user_id' => $userId,
            'effective_from' => $from,
            'distance_unit' => 'mi',
            'currency' => 'GBP',
            'car_rate' => $car,
            'car_threshold' => '10000.000',
            'car_rate_after' => '0.2500',
            'bike_rate' => '0.2400',
            'passenger_rate' => '0.0500',
            'employer_car_rate' => '0.3500',
            'employer_bike_rate' => null,
            'source' => 'HMRC approved mileage allowance payments',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->table('mileage_rate_sets')->insert([$rates('2011-04-06', '0.4500'), $rates('2026-04-06', '0.5500')])->saveData();

        $journeys = [
            ['Office', 'Client site', 27.0, true, 'Site visit', true],
            ['Office', 'Head office, Belfast', 31.0, true, 'Team meeting', true],
            ['Home', 'Airport', 22.0, true, null, false],
        ];
        foreach ($journeys as $order => [$from, $to, $miles, $return, $purpose, $business]) {
            $this->table('saved_journeys')->insert([
                'user_id' => $userId,
                'from_place' => $from,
                'to_place' => $to,
                'distance_km' => $km($miles),
                'is_return_default' => $return,
                'purpose_default' => $purpose,
                'is_business_default' => $business,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
        }

        // Every nine days from 15 April 2025 to late September 2026.
        $purposes = ['Site visit', 'Client meeting', 'Supplier review', 'Site survey', 'Quarterly review'];
        $trips = [];
        $day = new DateTimeImmutable('2025-04-15');
        $last = new DateTimeImmutable('2026-09-25');
        for ($i = 0; $day <= $last; $i++, $day = $day->modify('+9 days')) {
            [$from, $to, $miles, $return] = match ($i % 3) {
                0 => ['Office', 'Client site', 54.0, true],
                1 => ['Office', 'Head office, Belfast', 62.0, true],
                default => ['Ballymena', 'Belfast', 54.0 + ($i % 5), false],
            };
            $trips[] = [
                'vehicle_id' => $golf,
                'created_by' => $userId,
                'travelled_on' => $day->format('Y-m-d'),
                'from_place' => $from,
                'to_place' => $to,
                'is_return' => $return,
                'distance_km' => $km($miles),
                'odometer_start_km' => null,
                'odometer_end_km' => null,
                'is_business' => true,
                'purpose' => $purposes[$i % count($purposes)],
                'passengers' => $i % 7 === 3 ? 2 : ($i % 7 === 5 ? 1 : 0),
                'notes' => null,
                'created_at' => $day->format('Y-m-d') . ' 18:00:00',
                'updated_at' => $day->format('Y-m-d') . ' 18:00:00',
            ];
        }
        foreach (['2025-08-02' => 'Portrush', '2026-07-18' => 'Newcastle'] as $on => $to) {
            $trips[] = [
                'vehicle_id' => $golf, 'created_by' => $userId, 'travelled_on' => $on, 'from_place' => 'Home',
                'to_place' => $to, 'is_return' => true, 'distance_km' => $km(70.0), 'odometer_start_km' => null,
                'odometer_end_km' => null, 'is_business' => false, 'purpose' => null, 'passengers' => 0,
                'notes' => 'Day out', 'created_at' => $on . ' 20:00:00', 'updated_at' => $on . ' 20:00:00',
            ];
        }
        $this->table('trips')->insert($trips)->saveData();

        // A toll receipt on the latest Belfast trip.
        $toll = null;
        $belfast = "SELECT id FROM trips WHERE to_place = 'Belfast' ORDER BY travelled_on DESC";
        foreach ($this->fetchAll($belfast) as $row) {
            if (is_array($row)) {
                $toll = self::intValue($row['id'] ?? null);
                break;
            }
        }
        $directory = Kernel::settings()->uploadPath . '/attachments';
        if ($toll === null || (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample toll receipt skipped.</comment>');

            return;
        }
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );
        $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents(Kernel::settings()->uploadPath . '/' . $stored, $png);
        $this->table('attachments')->insert([
            'vehicle_id' => $golf,
            'owner_type' => 'trip',
            'owner_id' => $toll,
            'filename' => 'Toll receipt.png',
            'mime' => 'image/png',
            'size' => strlen($png),
            'stored_path' => $stored,
            'uploaded_at' => $now,
            'uploaded_by' => $userId,
        ])->saveData();
    }

    /**
     * The sale pack (spec.md §7.19) shows every block for the Golf: invoices
     * on most service records (not the DIY wipers or the tyre repair), both
     * MOT certificates, and a dashboard photo taken on collection, the day it
     * was bought, so the pack can say how far it has gone since.
     */
    private function seedSalePack(string $now): void
    {
        $golf = $this->vehicleIds()['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        $directory = Kernel::settings()->uploadPath . '/attachments';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample invoices skipped.</comment>');

            return;
        }
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF\n";
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );
        $attachments = [];
        $attach = function (
            string $owner,
            int $ownerId,
            string $name,
            string $contents,
            string $mime,
        ) use (
            $golf,
            $now,
            &$attachments,
        ): void {
            $stored = 'attachments/' . bin2hex(random_bytes(16)) . ($mime === 'image/png' ? '.png' : '.pdf');
            file_put_contents(Kernel::settings()->uploadPath . '/' . $stored, $contents);
            $attachments[] = [
                'vehicle_id' => $golf,
                'owner_type' => $owner,
                'owner_id' => $ownerId,
                'filename' => $name,
                'mime' => $mime,
                'size' => strlen($contents),
                'stored_path' => $stored,
                'uploaded_at' => $now,
            ];
        };

        foreach ($this->fetchAll('SELECT id, vehicle_id, performed_on, title, vendor FROM maintenance_entries') as $row) {
            if (
                is_array($row)
                && self::intValue($row['vehicle_id'] ?? null) === $golf
                && is_string($row['vendor'] ?? null)
                && ($row['title'] ?? null) !== 'Tyre repair, front right'
            ) {
                $on = substr(self::stringValue($row['performed_on']), 0, 10);
                $attach('maintenance', self::intValue($row['id']), 'Invoice ' . $on . '.pdf', $pdf, 'application/pdf');
            }
        }
        foreach ($this->fetchAll('SELECT id, vehicle_id, type, start_on FROM compliance_documents') as $row) {
            $ours = is_array($row) && self::intValue($row['vehicle_id'] ?? null) === $golf;
            if ($ours && ($row['type'] ?? null) === 'inspection') {
                $on = substr(self::stringValue($row['start_on']), 0, 10);
                $attach('compliance', self::intValue($row['id']), 'MOT certificate ' . $on . '.pdf', $pdf, 'application/pdf');
            }
        }

        $photo = $this->insertRow('odometer_readings', [
            'vehicle_id' => $golf,
            'reading_km' => '31200.000',
            'recorded_at' => self::localNoon('2021-03-14'),
            'source' => 'manual',
            'note' => 'On collection from the dealer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $attach('odometer', $photo, 'Dashboard 2021-03-14.png', $png, 'image/png');

        $this->table('attachments')->insert($attachments)->saveData();
    }

    /**
     * A year of tyres (spec.md §7.17). The Golf starts with the summers already
     * on it, gets winters in November (the summers go into storage), swaps
     * back in March (the winters into the *Winter wheels* set), has its worn
     * fronts replaced (linked to the "Two front tyres" service record), a
     * puncture repaired, one rotation, and a damaged tyre replaced, so a
     * retired tyre shows its cost per distance. The bike has its rear
     * replaced once. Odometers come from each vehicle's own mileage series,
     * so no reading looks implausible; state comes from the replay.
     *
     * Tread depths (Phase 11.2): taken at fitting and on every swap, and in
     * three checks across the year, so the old Goodyears now at the front
     * have an estimate and a *due* tyre reminder; the winter set's DOT date
     * makes it *upcoming* for age. The bike's rear is measured at fitting
     * and once since.
     */
    private function seedTyres(string $now): void
    {
        $ids = $this->vehicleIds();
        $golf = $ids['LB19 KTR'];
        $bike = $ids['MT20 BKE'];

        $tyre = function (
            int $vehicle,
            string $brand,
            string $model,
            string $size,
            ?string $season,
            string $dot,
        ) use ($now): int {
            $week = (int) substr($dot, 0, 2);
            $made = (new DateTimeImmutable('@0'))->setISODate(2000 + (int) substr($dot, 2), $week, 1)->format('Y-m-d');

            return $this->insertRow('tyres', [
                'vehicle_id' => $vehicle, 'set_id' => null, 'brand' => $brand, 'model' => $model, 'size' => $size,
                'season' => $season, 'dot_code' => $dot, 'manufactured_on' => $made, 'status' => 'stored',
                'position' => null, 'retired_reason' => null, 'notes' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        };
        $summer = static fn (string $dot): array => ['Goodyear', 'EfficientGrip Performance 2', '205/55 R16 91V', null, $dot];
        $s = array_map(fn (string $dot): int => $tyre($golf, ...$summer($dot)), ['1823', '1823', '1923', '1923']);
        $w = array_map(
            fn (string $dot): int => $tyre($golf, 'Continental', 'WinterContact TS 870', '205/55 R16 91H', 'winter', $dot),
            ['5020', '5020', '5020', '5120'],
        );
        $f = array_map(
            fn (string $dot): int => $tyre($golf, 'Michelin', 'Primacy 4+', '205/55 R16 91V', null, $dot),
            ['0526', '0526', '2926'],
        );
        $bikeFront = $tyre($bike, 'Michelin', 'Road 6', '120/70 ZR17', null, '4424');
        $bikeRear = [
            $tyre($bike, 'Michelin', 'Road 6', '180/55 ZR17', null, '4424'),
            $tyre($bike, 'Michelin', 'Road 6', '180/55 ZR17', null, '2226'),
        ];

        $winterWheels = $this->insertRow('tyre_sets', [
            'vehicle_id' => $golf, 'name' => 'Winter wheels', 'storage_location' => 'Kwik Fit Southend, ref 4471',
            'notes' => 'On their own steel rims.', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->execute(sprintf('UPDATE tyres SET set_id = %d WHERE id IN (%s)', $winterWheels, implode(', ', $w)));

        $record = null;
        foreach ($this->fetchAll('SELECT id, title FROM maintenance_entries') as $row) {
            if (is_array($row) && ($row['title'] ?? null) === 'Two front tyres') {
                $record = self::intValue($row['id'] ?? null);
            }
        }
        $repairOn = '2026-05-02';
        $repairKm = $this->odometerOn($golf, $repairOn);
        $repair = $this->serviceRecord($golf, $repairOn, $repairKm, 'Tyre repair, front right', '25.000', 'Kwik Fit', $now);
        $bikeRearOn = '2026-08-10';
        $bikeKm = $this->odometerOn($bike, $bikeRearOn);
        $bikeTitle = '1 × Michelin Road 6, rear';
        $bikeRecord = $this->serviceRecord($bike, $bikeRearOn, $bikeKm, $bikeTitle, '169.000', 'Rider Tyres, Leeds', $now);

        $on = static fn (int $tyre, TyrePosition $p): TyreChangeLine => new TyreChangeLine($tyre, TyreLineAction::On, $p);
        $off = static fn (int $tyre, TyrePosition $p): TyreChangeLine => new TyreChangeLine($tyre, TyreLineAction::Off, $p);
        $retire = static fn (int $tyre, TyrePosition $p): TyreChangeLine => new TyreChangeLine($tyre, TyreLineAction::Retire, $p);
        $move = static fn (int $tyre, TyrePosition $p): TyreChangeLine => new TyreChangeLine($tyre, TyreLineAction::Move, $p);
        $measure = static fn (int $tyre, TyrePosition $p, string $mm): TyreChangeLine
            => new TyreChangeLine($tyre, TyreLineAction::Measure, $p, $mm);
        $depths = self::withDepths(...);
        $fl = TyrePosition::FrontLeft;
        $fr = TyrePosition::FrontRight;
        $rl = TyrePosition::RearLeft;
        $rr = TyrePosition::RearRight;
        $road = [$fl, $fr, $rl, $rr];

        /**
         * vehicle, kind, date, linked record (its odometer covers the change), lines, retire reasons,
         * and an odometer beyond the mileage series (a check writes its own reading)
         *
         * @var list<array{
         *     0: int, 1: TyreChangeKind, 2: string, 3: ?int, 4: list<TyreChangeLine>, 5: array<int, string>, 6?: string
         * }> $plan
         */
        $plan = [
            [$golf, TyreChangeKind::Existing, '2025-09-28', null, [
                ...$depths(array_map($on, $s, $road), ['4.2', '4.1', '6.2', '6.2']),
            ], []],
            [$golf, TyreChangeKind::Fit, '2025-11-08', null, [
                ...$depths(array_map($off, $s, $road), ['4.0', '3.9', '6.0', '6.0']),
                ...$depths(array_map($on, $w, $road), ['7.0', '7.0', '7.2', '7.1']),
            ], []],
            [$golf, TyreChangeKind::Swap, '2026-03-08', null, [
                ...$depths(array_map($off, $w, $road), ['6.2', '6.3', '6.6', '6.6']),
                ...$depths(array_map($on, $s, $road), ['4.0', '3.9', '6.0', '6.0']),
            ], []],
            [$golf, TyreChangeKind::Fit, '2026-03-10', $record, [
                $retire($s[0], $fl), $retire($s[1], $fr), ...$depths([$on($f[0], $fl), $on($f[1], $fr)], ['8.0', '8.0']),
            ], [$s[0] => 'worn', $s[1] => 'worn']],
            [$golf, TyreChangeKind::Check, '2026-04-20', null, [
                $measure($f[0], $fl, '7.7'), $measure($f[1], $fr, '7.7'),
                $measure($s[2], $rl, '5.5'), $measure($s[3], $rr, '5.6'),
            ], []],
            [$golf, TyreChangeKind::Repair, $repairOn, $repair, [new TyreChangeLine($f[1], TyreLineAction::Repair, $fr)], []],
            [$golf, TyreChangeKind::Check, '2026-06-20', null, [
                $measure($f[0], $fl, '7.1'), $measure($f[1], $fr, '7.2'),
                $measure($s[2], $rl, '4.7'), $measure($s[3], $rr, '4.8'),
            ], []],
            [$golf, TyreChangeKind::Rotate, '2026-07-12', null, [
                $move($f[0], $rl), $move($f[1], $rr), $move($s[2], $fl), $move($s[3], $fr),
            ], []],
            [$golf, TyreChangeKind::Fit, '2026-08-22', null, [
                $retire($f[0], $rl)->withTread('6.4'), $on($f[2], $rl)->withTread('8.0'),
            ], [$f[0] => 'damaged']],
            [$golf, TyreChangeKind::Check, '2026-09-20', null, [
                $measure($s[2], $fl, '3.3'), $measure($s[3], $fr, '3.5'),
                $measure($f[2], $rl, '7.9'), $measure($f[1], $rr, '6.3'),
            ], [], '78700.000'],
            [$bike, TyreChangeKind::Existing, '2026-03-20', null, [
                $on($bikeFront, TyrePosition::Front)->withTread('3.1'), $on($bikeRear[0], TyrePosition::Rear)->withTread('2.4'),
            ], []],
            [$bike, TyreChangeKind::Fit, $bikeRearOn, $bikeRecord, [
                $retire($bikeRear[0], TyrePosition::Rear)->withTread('1.6'),
                $on($bikeRear[1], TyrePosition::Rear)->withTread('6.0'),
            ], [$bikeRear[0] => 'worn']],
            [$bike, TyreChangeKind::Check, '2026-09-19', null, [
                $measure($bikeFront, TyrePosition::Front, '2.6'), $measure($bikeRear[1], TyrePosition::Rear, '5.4'),
            ], [], '22050.000'],
        ];

        $changes = [];
        $readings = [];
        foreach ($plan as $step) {
            [$vehicle, $kind, $date, $linked, $lines, $reasons] = $step;
            $km = $step[6] ?? ($linked === null ? $this->odometerOn($vehicle, $date) : $this->recordOdometer($linked));
            $id = $this->insertRow('tyre_changes', [
                'vehicle_id' => $vehicle, 'kind' => $kind->value, 'done_on' => $date, 'odometer_km' => $km,
                'maintenance_entry_id' => $linked, 'note' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($lines as $line) {
                $this->table('tyre_change_lines')->insert([
                    'change_id' => $id, 'tyre_id' => $line->tyreId, 'action' => $line->action->value,
                    'position' => $line->position?->value, 'tread_mm' => $line->treadMm,
                ])->saveData();
            }
            foreach ($reasons as $tyreId => $reason) {
                $this->execute(sprintf("UPDATE tyres SET retired_reason = '%s' WHERE id = %d", $reason, $tyreId));
            }
            if ($linked === null) {
                $readings[] = [
                    'vehicle_id' => $vehicle, 'reading_km' => $km, 'recorded_at' => self::localNoon($date),
                    'source' => 'tyre', 'tyre_change_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $day = LocalTime::parseDate($date) ?? throw new RuntimeException($date);
            $changes[$vehicle][] = new TyreChange(
                $id,
                $vehicle,
                $kind,
                new TyreChangeData($day, $km, $linked),
                $lines,
                new DateTimeImmutable($now),
                new DateTimeImmutable($now),
            );
        }
        $this->table('odometer_readings')->insert($readings)->saveData();

        // Store what the replay says, as the app does after every change.
        foreach ($changes as $vehicleChanges) {
            $result = TyreReplay::run($vehicleChanges);
            if (!$result instanceof TyreReplayResult) {
                throw new RuntimeException('The demo tyre changes do not replay.');
            }
            foreach ($result->states as $tyreId => $state) {
                $this->execute(sprintf(
                    "UPDATE tyres SET status = '%s', position = %s WHERE id = %d",
                    $state->status->value,
                    $state->position === null ? 'NULL' : "'" . $state->position->value . "'",
                    $tyreId,
                ));
            }
        }
    }

    /**
     * The lines with one tread depth each, in order.
     *
     * @param list<TyreChangeLine> $lines
     * @param list<string> $mm
     * @return list<TyreChangeLine>
     */
    private static function withDepths(array $lines, array $mm): array
    {
        $measured = [];
        foreach ($lines as $i => $line) {
            $measured[] = $line->withTread($mm[$i] ?? null);
        }

        return $measured;
    }

    /**
     * A `tyres` service record with its odometer reading at local noon.
     */
    private function serviceRecord(
        int $vehicle,
        string $date,
        string $km,
        string $title,
        string $cost,
        string $vendor,
        string $now,
    ): int {
        $id = $this->insertRow('maintenance_entries', [
            'vehicle_id' => $vehicle, 'schedule_id' => null, 'performed_on' => $date, 'odometer_km' => $km,
            'category' => 'tyres', 'title' => $title, 'description' => null, 'cost' => $cost, 'vendor' => $vendor,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->table('odometer_readings')->insert([
            'vehicle_id' => $vehicle, 'reading_km' => $km, 'recorded_at' => self::localNoon($date),
            'source' => 'maintenance', 'maintenance_entry_id' => $id, 'created_at' => $now, 'updated_at' => $now,
        ])->saveData();

        return $id;
    }

    private function recordOdometer(int $entryId): string
    {
        $row = $this->fetchRow(sprintf('SELECT odometer_km FROM maintenance_entries WHERE id = %d', $entryId));
        $km = is_array($row) ? ($row['odometer_km'] ?? $row[0] ?? null) : null;

        return is_numeric($km) ? number_format((float) $km, 3, '.', '') : throw new RuntimeException('No odometer.');
    }

    /**
     * The vehicle's odometer at local noon on $date, read off its mileage
     * series (linear between the readings either side, in whole km).
     */
    private function odometerOn(int $vehicle, string $date): string
    {
        $at = self::localNoon($date);
        $before = null;
        $after = null;
        $rows = $this->fetchAll(sprintf('SELECT reading_km, recorded_at FROM odometer_readings WHERE vehicle_id = %d', $vehicle));
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['reading_km'] ?? null) || !is_string($row['recorded_at'] ?? null)) {
                continue;
            }
            $point = [substr($row['recorded_at'], 0, 19), (float) $row['reading_km']];
            if ($point[0] <= $at && ($before === null || $point[0] > $before[0])) {
                $before = $point;
            } elseif ($point[0] > $at && ($after === null || $point[0] < $after[0])) {
                $after = $point;
            }
        }
        // Rounded up after a reading and down before one, so the series never goes backwards.
        $km = match (true) {
            $before !== null && $after !== null => ceil($before[1] + ($after[1] - $before[1])
                * (strtotime($at . ' UTC') - strtotime($before[0] . ' UTC'))
                / max(1, strtotime($after[0] . ' UTC') - strtotime($before[0] . ' UTC'))),
            $before !== null => ceil($before[1]),
            $after !== null => floor($after[1]),
            default => throw new RuntimeException('No readings to place a tyre change on.'),
        };

        return number_format($km, 3, '.', '');
    }

    /**
     * Noon in Europe/London on $date, as a UTC timestamp for the database.
     */
    private static function localNoon(string $date): string
    {
        return (new DateTimeImmutable($date . ' 12:00', new DateTimeZone('Europe/London')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Insert one row and return its id (MAX(id) works on every engine; the
     * seeder is the only writer).
     *
     * @param array<string, mixed> $values
     */
    private function insertRow(string $table, array $values): int
    {
        $this->table($table)->insert($values)->saveData();
        $row = $this->fetchRow('SELECT MAX(id) AS id FROM ' . $table);

        return self::intValue(is_array($row) ? ($row['id'] ?? $row[0] ?? null) : null);
    }

    /**
     * @return array<string, int> vehicle id by registration
     */
    private function vehicleIds(): array
    {
        $ids = [];
        foreach ($this->fetchAll('SELECT id, registration FROM vehicles') as $row) {
            if (is_array($row) && is_string($row['registration'] ?? null)) {
                $ids[$row['registration']] = self::intValue($row['id'] ?? null);
            }
        }

        return $ids;
    }

    /**
     * Simulated fill-ups: the tank's deficit since the last full fill is what
     * a full fill adds, so full-to-full economy comes out at $kmPerUnit.
     *
     * @param int $partialEvery every Nth fill is partial (0: none; negative: all but every Nth)
     * @param int|null $missed index of a fill-up that happens but is never logged
     * @param list<string> $grades grade codes, in turn (empty: none recorded)
     * @param int $ungraded how many of the first fill-ups have no grade (logged before grades existed)
     * @param float $noise how far each tank's economy strays, either way (0.07: ±7%)
     * @param float $winter extra fuel used in mid-January, tapering to as much less in mid-July
     * @param array<string, float> $burnedFactor fuel used on a grade, relative to the others (0.97: 3% less)
     * @return list<array<string, mixed>>
     */
    private function fillUps(
        int $vehicleId,
        string $start,
        int $count,
        float $km,
        float $kmPerFill,
        float $kmPerUnit,
        float $price,
        string $fuel,
        int $partialEvery,
        ?int $missed,
        string $now,
        array $grades = [],
        int $ungraded = 0,
        float $noise = 0.07,
        float $winter = 0.0,
        array $burnedFactor = [],
    ): array {
        $rows = [];
        $deficit = 0.0;
        $time = (int) strtotime($start . ' 08:00 UTC');
        $afterGap = false;
        $burning = null; // the grade of the last full fill, burned until the next

        for ($i = 0; $i < $count; $i++) {
            $distance = $kmPerFill * (0.8 + mt_rand(0, 400) / 1000);
            $km += $distance;
            // The same mt_rand() calls whatever the options, so other vehicles' figures never move.
            $spread = 1 - $noise + mt_rand(0, 140) / 1000 * ($noise / 0.07);
            $season = 1 + $winter * cos(2 * M_PI * ((int) gmdate('z', $time) - 14) / 365.25);
            $deficit += $distance / $kmPerUnit / $spread * $season * ($burnedFactor[$burning ?? ''] ?? 1.0);
            $time += (int) ($distance / $kmPerFill * 11 * 86400) + mt_rand(0, 36000);

            $partial = $partialEvery > 0 ? ($i % $partialEvery === $partialEvery - 1)
                : ($partialEvery < 0 && $i % -$partialEvery !== 0);
            $volume = $partial ? $deficit * 0.55 : $deficit;
            $deficit -= $volume;

            if ($i === $missed) {
                $afterGap = true;

                continue;
            }

            $unitPrice = round($price * (1 + 0.04 * sin($i / 5)) + mt_rand(-10, 10) / 1000, 3);
            $grade = $grades === [] || $i < $ungraded ? null : $grades[$i % count($grades)];
            // Super costs more at the pump; rapid charging far more than home; the hotel's AC was free.
            $unitPrice = match ($grade) {
                'e5_97' => $unitPrice + 0.12,
                'dc_rapid' => 0.69,
                'ac' => 0.0,
                default => $unitPrice,
            };
            $station = match ($grade) {
                'home' => 'Home',
                'dc_rapid' => 'Ionity',
                'ac' => 'Hotel car park',
                default => $fuel === 'ev' ? 'Home' : ($i % 3 === 0 ? 'Tesco Extra' : 'Shell'),
            };
            $rows[] = [
                'vehicle_id' => $vehicleId,
                'filled_at' => gmdate('Y-m-d H:i:s', $time),
                'odometer_km' => number_format($km, 3, '.', ''),
                'fuel' => $fuel,
                'grade' => $grade,
                'volume' => number_format($volume, 3, '.', ''),
                'price_per_unit' => number_format($unitPrice, 6, '.', ''),
                'total_cost' => number_format(round($volume * $unitPrice, 2), 3, '.', ''),
                'is_partial' => $partial,
                'is_missed_previous' => $afterGap,
                'station' => $station,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $afterGap = false;
            if (!$partial) {
                $burning = $grade;
            }
        }

        return $rows;
    }

    /**
     * Something for each economy check state (spec.md §7.3) on the Golf: one
     * mistyped odometer (300 km too high on the 20th fill-up: its tank reads
     * *less than usual*, the next *more*, a pair naming it) and one genuinely
     * thirsty January tank (40% more fuel) the owner confirmed as right.
     *
     * @param list<array<string, mixed>> $rows the Golf's fill-ups, as fillUps() made them
     * @return array{list<array<string, mixed>>, array{filled_at: string, consumption: string}}
     *     the rows, and which one to confirm with what
     */
    private static function economyChecks(array $rows): array
    {
        [$typo, $thirsty] = [19, 9];
        foreach ([$typo - 1, $typo, $typo + 1, $thirsty - 1, $thirsty] as $i) {
            if ($rows[$i]['is_partial'] !== false || ($i !== $typo - 1 && $rows[$i]['is_missed_previous'] !== false)) {
                throw new LogicException('The demo economy checks need full fill-ups there.');
            }
        }

        $rows[$typo]['odometer_km'] = Decimal::add(self::stringValue($rows[$typo]['odometer_km']), '300');

        $volume = Decimal::multiply(self::stringValue($rows[$thirsty]['volume']), '1.4', 3);
        $rows[$thirsty]['volume'] = $volume;
        $rows[$thirsty]['total_cost'] = Decimal::multiply($volume, self::stringValue($rows[$thirsty]['price_per_unit']), 2);
        $rows[$thirsty]['notes'] = 'Snow, roof box and a trailer to the tip';
        $distance = Decimal::subtract(
            self::stringValue($rows[$thirsty]['odometer_km']),
            self::stringValue($rows[$thirsty - 1]['odometer_km']),
        );

        // Confirmed exactly as *Looks right* stores it.
        return [$rows, [
            'filled_at' => self::stringValue($rows[$thirsty]['filled_at']),
            'consumption' => Decimal::divide(Decimal::multiply($volume, '100', 3), $distance, 6),
        ]];
    }

    /**
     * A plug-in hybrid on one rising odometer: charged at home every other
     * evening, filled with petrol every three weeks. Worked out without
     * mt_rand(), so the other vehicles' figures stay as they were.
     *
     * @return list<array<string, mixed>>
     */
    private function plugInHybrid(int $vehicleId, string $now): array
    {
        $rows = [];
        $km = 31200.0;
        $start = (int) strtotime('2026-01-10 00:00 UTC');
        for ($day = 1; $day <= 150; $day++) {
            $km += 38 + 12 * sin($day);
            $petrol = $day % 21 === 0;
            if (!$petrol && $day % 2 !== 0) {
                continue;
            }
            $volume = $petrol ? 27 + 4 * sin($day / 7) : 10.5 + 1.5 * sin($day / 3);
            $price = $petrol ? round(1.459 + 0.03 * sin($day / 30), 3) : 0.285;
            $rows[] = [
                'vehicle_id' => $vehicleId,
                'filled_at' => gmdate('Y-m-d H:i:s', $start + $day * 86400 + ($petrol ? 8 : 19) * 3600),
                'odometer_km' => number_format($km, 3, '.', ''),
                'fuel' => $petrol ? 'petrol' : 'ev',
                'grade' => $petrol ? 'e10_95' : 'home',
                'volume' => number_format($volume, 3, '.', ''),
                'price_per_unit' => number_format($price, 6, '.', ''),
                'total_cost' => number_format(round($volume * $price, 2), 3, '.', ''),
                'is_partial' => false,
                'is_missed_previous' => false,
                'station' => $petrol ? 'Shell' : 'Home',
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Drivers return integers as int or numeric string.
     */
    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a string.');
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
