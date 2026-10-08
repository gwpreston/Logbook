<?php

declare(strict_types=1);

use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Kernel;
use Logbook\Service\FuelPrices\Demo\DemoPriceProvider;
use Logbook\Service\Tyre\TyreReplay;
use Logbook\Service\Tyre\TyreReplayResult;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Security\PasswordHasher;
use Phinx\Seed\AbstractSeed;

/**
 * Sample data for local development: a demo owner (an admin) and a small
 * garage (the vehicles from the design mock-ups, one of them written off
 * and archived), and a second user the owner shares two vehicles with (Phase
 * 19): Log without costs on the self-charging hybrid, whose fill-ups they
 * partly logged, and View on the Golf.
 *
 *   ./bin/dev-setup.sh --with-sample-data
 *   vendor/bin/phinx seed:run -e development -s DemoDataSeeder
 *
 * The passwords are new on every run (Phase 33.1): bin/dev-setup.sh passes
 * DEMO_PASSWORD and PARTNER_PASSWORD and prints them; run directly, the
 * seeder makes them up and prints them. On a database that already has the
 * sample users it only sets the new passwords (their sessions end, as any
 * password change); on one with other accounts it adds nothing (reset first
 * with `./bin/dev-setup.sh --reset`). Both users have confirmed addresses
 * (`demo@example.test`, `partner@example.test`), so a forgotten-password
 * email can be tried in Mailpit. Refuses to run in production.
 */
final class DemoDataSeeder extends AbstractSeed
{
    /** Where the generated fill-ups were bought (Phase 30.1), in turn. */
    private const array STATION_ROTA = [
        'Tesco Extra', 'Shell', 'Maxol Antrim', 'Tesco Extra.', 'Applegreen M2', 'Shell', 'Texaco Larne',
    ];

    public const string USERNAME = 'demo';
    public const string PARTNER = 'partner';
    public const string EMAIL = 'demo@example.test';
    public const string PARTNER_EMAIL = 'partner@example.test';
    /** No 0/O, 1/l/I: easy to read off a terminal. */
    private const string ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int PASSWORD_LENGTH = 20;

    /**
     * The day the sample dates were written for (every date below is
     * relative to it, Phase 35.1): `forDemo()` moves them all by the whole
     * weeks between it and the day given, so the history always ends just
     * before today. Weeks, so the weekday patterns (commutes) keep their shape.
     */
    public const string ANCHOR = '2026-10-06';

    /** Days every seeded date moves by (always 0 outside `forDemo()`). */
    private static int $shiftDays = 0;

    private string $password = '';
    private string $partnerPassword = '';
    private bool $demo = false;
    private ?DateTimeImmutable $today = null;
    private ?string $uploadPath = null;

    /**
     * Seed for Logbook's demo mode (spec.md §7.36), by the app and not by
     * Phinx: the one account `demo` with this password, every date placed
     * relative to $today, no output, and allowed in production.
     */
    public function forDemo(string $password, DateTimeImmutable $today, string $uploadPath): self
    {
        $this->demo = true;
        $this->password = $password;
        $this->today = $today;
        $this->uploadPath = $uploadPath;

        return $this;
    }

    /** Where the sample paperwork is written: the app's UPLOAD_PATH. */
    private function uploadPath(): string
    {
        return $this->uploadPath ?? Kernel::settings()->uploadPath;
    }

    /**
     * An ISO date as it falls relative to today: the sample's own date in
     * the sample's own time, moved on by the shift (a no-op outside the demo).
     */
    private static function day(string $date): string
    {
        return self::$shiftDays === 0
            ? $date
            : gmdate('Y-m-d', (int) strtotime($date . ' UTC') + self::$shiftDays * 86400);
    }

    public function run(): void
    {
        if (!$this->demo && Kernel::settings()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder is for development only (APP_ENV=production).');
        }

        if ($this->demo && $this->today instanceof DateTimeImmutable) {
            $days = intdiv(
                (int) strtotime($this->today->format('Y-m-d') . ' UTC') - (int) strtotime(self::ANCHOR . ' UTC'),
                86400,
            );
            self::$shiftDays = (int) floor($days / 7) * 7;
        } else {
            self::$shiftDays = 0;
        }

        $this->partnerPassword = self::passwordFrom('PARTNER_PASSWORD');
        if (!$this->demo) {
            $this->password = self::passwordFrom('DEMO_PASSWORD');
        }
        $hasher = new PasswordHasher();

        if ($this->demo) {
            $existing = $this->fetchRow('SELECT COUNT(*) AS n FROM users');
            if (is_array($existing) && self::intValue($existing['n'] ?? $existing[0] ?? 0) > 0) {
                throw new RuntimeException('The demo is seeded into an empty database only.');
            }
        } elseif ($this->sampleUsersExist()) {
            $now = gmdate('Y-m-d H:i:s');
            foreach ([self::USERNAME => $this->password, self::PARTNER => $this->partnerPassword] as $username => $password) {
                $this->execute(
                    'UPDATE users SET password_hash = ?, updated_at = ? WHERE username = ?',
                    [$hasher->hash($password), $now, $username],
                );
                $this->execute(
                    'DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE username = ?)',
                    [$username],
                );
            }
            $this->getOutput()->writeln(sprintf(
                '<info>The sample users exist; new passwords set. Sign in as "%s" with "%s" (or "%s" with "%s").</info>',
                self::USERNAME,
                $this->password,
                self::PARTNER,
                $this->partnerPassword,
            ));

            return;
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
            'password_hash' => $hasher->hash($this->password),
            'email' => self::EMAIL,
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
            'disposal' => null,
            'disposal_incident_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);

        $this->table('vehicles')->insert([
            $vehicle([
                'type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf 1.5 TSI Life', 'year' => 2019,
                'registration' => 'LB19 KTR', 'vin' => 'WVWZZZCDZKW123456', 'fuel_type' => 'petrol',
                'default_grade' => 'e10_95', 'capacity' => '50.000',
                'purchase_date' => self::day('2021-03-14'), 'purchase_price' => '14250.000',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Toyota', 'model' => 'Corolla 1.8 Hybrid', 'year' => 2022,
                'registration' => 'LK22 VXN', 'fuel_type' => 'hybrid', 'capacity' => '43.000',
                // Bought nearly new on a 48-month PCP (Phase 29.2): the purchase price is its cash price.
                'purchase_date' => self::day('2024-04-01'), 'purchase_price' => '22995.000',
            ]),
            $vehicle([
                'type' => 'bike', 'nickname' => 'Street Triple', 'make' => 'Triumph', 'model' => 'Street Triple R',
                'year' => 2020, 'registration' => 'MT20 BKE', 'fuel_type' => 'petrol', 'capacity' => '15.000',
                'purchase_date' => self::day('2023-04-22'), 'purchase_price' => '7800.000',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Kia', 'model' => 'EV6 GT-Line', 'year' => 2023,
                'registration' => 'EV23 KIA', 'fuel_type' => 'ev', 'default_grade' => 'home',
                'capacity' => '77.400', 'currency' => 'EUR',
                // Leased (a lease agreement, Phase 29.2): no purchase price, so its cost of ownership is its
                // running costs, the rentals included.
                'purchase_date' => self::day('2024-02-10'), 'purchase_price' => null,
                // Leased new, so no MOT certificate yet: its first MOT is 3 years on (spec.md §7.1, Phase 21.2).
                'first_registered_on' => self::day('2024-02-09'), 'first_inspection_due_on' => self::day('2027-02-09'),
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Ford', 'model' => 'Fiesta 1.0 EcoBoost', 'year' => 2014,
                'registration' => 'WR14 FNE', 'fuel_type' => 'petrol', 'capacity' => '42.000',
                'purchase_date' => self::day('2016-06-30'), 'purchase_price' => '6500.000',
                // Written off (Phase 27.2): the settlement is the sale; the incident is linked once it exists.
                'sale_date' => self::day('2025-11-20'), 'sale_price' => '2100.000',
                'status' => 'archived', 'archived_at' => self::day('2025-11-20') . ' 12:00:00', 'disposal' => 'written_off',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Mitsubishi', 'model' => 'Outlander 2.4 PHEV', 'year' => 2021,
                'registration' => 'PHV 1', 'fuel_type' => 'phev', 'default_grade' => 'e10_95', 'capacity' => '45.000',
                'purchase_date' => self::day('2025-12-05'), 'purchase_price' => '21450.000',
            ]),
            // An off-road trail bike: never road-registered, so no plate is drawn (Phase 34.1).
            $vehicle([
                'type' => 'bike', 'make' => 'Honda', 'model' => 'CRF250F', 'year' => 2022,
                'fuel_type' => 'petrol', 'capacity' => '6.300',
                'purchase_date' => self::day('2025-08-09'), 'purchase_price' => '3950.000',
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
        $this->seedIncidents($now, $userId);
        $this->seedIssues($now, $userId);
        $this->seedTrips($now, $userId);
        $this->seedPartner($now, $userId);
        $this->seedFinance($now, $userId);
        $this->seedStations($now, $userId);
        $this->seedFuelPrices($now, $userId);

        if ($this->demo) {
            // One account (spec.md §7.36): the partner's fill-ups become the owner's.
            $this->foldPartnerIntoOwner($userId);
            self::$shiftDays = 0;

            return;
        }

        $this->getOutput()->writeln(sprintf(
            '<info>Sample data added. Sign in as "%s" with "%s" (or "%s" with "%s").</info>',
            self::USERNAME,
            $this->password,
            self::PARTNER,
            $this->partnerPassword,
        ));
    }

    /**
     * The demo has one account (spec.md §7.36, #217): what the partner
     * added belongs to the owner, and the partner and its shares go.
     */
    private function foldPartnerIntoOwner(int $ownerId): void
    {
        $partner = $this->fetchRow("SELECT id FROM users WHERE username = '" . self::PARTNER . "'");
        if (!is_array($partner)) {
            return;
        }
        $partnerId = self::intValue($partner['id'] ?? $partner[0] ?? null);
        $authored = [
            'fuel_entries', 'maintenance_entries', 'compliance_documents', 'expense_entries',
            'tyre_changes', 'vehicle_valuations', 'odometer_readings',
        ];
        foreach ($authored as $table) {
            $this->execute(sprintf('UPDATE %s SET created_by = %d WHERE created_by = %d', $table, $ownerId, $partnerId));
        }
        $this->execute(sprintf('UPDATE attachments SET uploaded_by = %d WHERE uploaded_by = %d', $ownerId, $partnerId));
        $this->execute(sprintf('DELETE FROM vehicle_shares WHERE user_id = %d', $partnerId));
        $this->execute(sprintf('DELETE FROM users WHERE id = %d', $partnerId));
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
            'password_hash' => (new PasswordHasher())->hash($this->partnerPassword),
            'email' => self::PARTNER_EMAIL,
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
        $switch = self::day('2026-07-10');
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
            self::day('2025-09-20'),
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
            // Phase 32: the Golf's earlier years, so its true cost trend has fuel in every year.
            ...self::golfEarlierYears($ids['LB19 KTR'], $now),
            ...$golf,
            // The bike always takes super unleaded.
            ...$this->fillUps(
                $ids['MT20 BKE'],
                self::day('2026-03-15'),
                12,
                18500.0,
                230.0,
                19.5,
                1.529,
                'petrol',
                0,
                null,
                $now,
                grades: [
                    'e5_98',
                ],
            ),
            // EV6: charges in kWh, ~5.6 km/kWh, most of them partial; mostly at home, some rapid, one free.
            ...$this->fillUps(
                $ids['EV23 KIA'],
                self::day('2026-01-05'),
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
            ...$this->plugInHybrid($ids['PHV 1'], $now),
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
                $e10[] = [
                    'id' => self::intValue($row['id'] ?? null),
                    'time' => (int) strtotime(self::stringValue($row['filled_at'] ?? null) . ' UTC'),
                    'price' => self::floatValue($row['price_per_unit'] ?? null),
                    // Booleans come back as 0/1, "0"/"1" or t/f depending on the engine.
                    'partial' => in_array($row['is_partial'] ?? null, [true, 1, '1', 't'], true),
                ];
            }
        }
        usort($e10, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        $typo = null;
        foreach ($e10 as $candidate) {
            if ($candidate['partial']) {
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
                Decimal::multiply(number_format($typo['price'], 6, '.', ''), '10', 6),
                $typo['id'],
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
                'recorded_at' => gmdate(
                    'Y-m-d H:i:s',
                    (int) strtotime(sprintf(self::day('2025-10-01') . ' +%d months 09:00', $month)),
                ),
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
                'baseline_done_on' => self::day('2024-10-01'), 'baseline_done_km' => '55000.000',
                'last_done_on' => self::day('2025-10-02'), 'last_done_km' => '62100.000',
                'next_due_on' => self::day('2026-10-02'), 'next_due_km' => '78193.440',
            ]),
            $schedule([
                'category' => 'brakes', 'title' => 'Brake fluid', 'interval_months' => 24,
                'baseline_done_on' => self::day('2024-11-15'), 'last_done_on' => self::day('2024-11-15'),
                'next_due_on' => self::day('2026-11-15'),
            ]),
            // Every 500 mi, by distance only.
            $schedule([
                'vehicle_id' => $bike, 'category' => 'oil', 'title' => 'Clean and lube the chain', 'interval_km' => '804.672',
                'baseline_done_km' => '20600.000', 'last_done_km' => '20600.000', 'next_due_km' => '21404.672',
            ]),
            // Phase 24: the bike's yearly service is overdue, so *Needs attention* has a Now item.
            $schedule([
                'vehicle_id' => $bike, 'title' => 'Annual service', 'interval_months' => 12,
                'baseline_done_on' => self::day('2025-08-20'), 'last_done_on' => self::day('2025-08-20'),
                'next_due_on' => self::day('2026-08-20'),
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
                'performed_on' => self::day('2022-03-20'), 'odometer_km' => '38900.000', 'title' => 'Annual service',
                'description' => 'Oil and filter, brake fluid.', 'cost' => '165.000', 'vendor' => 'Main Street Motors',
            ]),
            $entry([
                'performed_on' => self::day('2023-03-18'), 'odometer_km' => '45600.000', 'title' => 'Annual service',
                'description' => 'Oil and filter, air filter, spark plugs.', 'cost' => '239.000',
                'vendor' => 'Main Street Motors',
            ]),
            $entry([
                'performed_on' => self::day('2024-10-01'), 'odometer_km' => '55000.000', 'title' => 'Annual service',
                'description' => 'Oil and filter, pollen filter.', 'cost' => '178.000', 'vendor' => 'Main Street Motors',
            ]),
            $entry([
                'schedule_id' => $serviceId, 'performed_on' => self::day('2025-10-02'), 'odometer_km' => '62100.000',
                'title' => 'Annual service', 'description' => 'Oil and filter, air filter, pollen filter.',
                'cost' => '189.000', 'vendor' => 'Main Street Motors',
            ]),
            $entry(['performed_on' => self::day('2026-01-20'), 'category' => 'other', 'title' => 'Wiper blades (DIY)']),
            $entry([
                'performed_on' => self::day('2026-03-10'), 'odometer_km' => '69800.000', 'category' => 'tyres',
                'title' => 'Two front tyres', 'cost' => '176.000', 'vendor' => 'Kwik Fit',
            ]),
            $entry([
                'performed_on' => self::day('2026-06-18'), 'odometer_km' => '74050.000', 'category' => 'brakes',
                'title' => 'Front brake pads', 'cost' => '95.500', 'vendor' => 'Main Street Motors',
            ]),
            // Phase 25: £178 typed as £1,780, for the cost check.
            $entry([
                'performed_on' => self::day('2026-04-14'), 'title' => 'Interim service',
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
                'provider' => 'Admiral', 'reference' => 'P-88213901', 'start_on' => self::day('2024-10-10'),
                'expiry_on' => self::day('2025-10-09'), 'cost' => '389.000',
            ]),
            $document([
                'provider' => 'Admiral', 'reference' => 'P-88213901', 'start_on' => self::day('2025-10-10'),
                'expiry_on' => self::day('2026-10-09'), 'cost' => '412.500', 'notes' => 'Fully comprehensive, protected NCD.',
            ]),
            // Last year's MOT and this year's, each with the mileage on the certificate.
            $document([
                'type' => 'inspection', 'provider' => 'Main Street Motors', 'reference' => '4403 1192 6650',
                'start_on' => self::day('2025-03-06'), 'expiry_on' => self::day('2026-03-05'),
                'cost' => '54.850', 'odometer_km' => '57800.000',
            ]),
            $document([
                'type' => 'inspection', 'provider' => 'Main Street Motors', 'reference' => '5512 8830 1127',
                'start_on' => self::day('2026-03-05'), 'expiry_on' => self::day('2027-03-04'),
                'cost' => '54.850', 'odometer_km' => '69050.000',
            ]),
            $document(['type' => 'registration', 'title' => 'V5C logbook', 'reference' => 'DVLA 4421 90871']),
            $document([
                'vehicle_id' => $ids['MT20 BKE'], 'provider' => 'Bennetts', 'start_on' => self::day('2026-04-22'),
                'expiry_on' => self::day('2027-04-21'), 'cost' => '189.000',
            ]),
            $document([
                'vehicle_id' => $ids['EV23 KIA'], 'provider' => 'Allianz', 'start_on' => self::day('2026-02-10'),
                'expiry_on' => self::day('2027-02-09'), 'cost' => '640.000',
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
     * Reminders of your own: one coming up and one already done. Schedules
     * and documents raise theirs on the first sync (opening Reminders, or
     * the scheduled task), so with them the calendar (Phase 34.3) has an
     * overdue service and a due insurance renewal in October, the brake
     * fluid in November and the chain, by distance only, with no date.
     */
    private function seedReminders(string $now): void
    {
        $golf = $this->vehicleIds()['LB19 KTR'];
        $this->table('reminders')->insert([
            [
                'vehicle_id' => $golf,
                'source' => 'manual',
                'title' => 'Winter tyres on',
                'notes' => 'Winter wheels are at Kwik Fit Southend, ref 4471.',
                'due_on' => self::day('2026-11-01'),
                'lead_time_days' => 14,
                'status' => 'upcoming',
                'closed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vehicle_id' => $golf,
                'source' => 'manual',
                'title' => 'Top up the screenwash',
                'notes' => null,
                'due_on' => self::day('2026-10-03'),
                'lead_time_days' => 7,
                'status' => 'done',
                'closed_at' => $now,
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
            $expense('LB19 KTR', self::day('2026-08-14'), 'parking', '18.500', 'Leeds station'),
            $expense('LB19 KTR', self::day('2026-07-02'), 'cleaning', '12.000', 'Car wash'),
            $expense('LB19 KTR', self::day('2026-04-01'), 'tax', '190.000', 'Vehicle excise duty'),
            $expense('LB19 KTR', self::day('2025-12-20'), 'tolls', '2.500', 'Dartford Crossing'),
            $expense('LB19 KTR', self::day('2026-09-06'), 'parking', '0.000', 'Free after 6pm'),
            $expense('MT20 BKE', self::day('2026-05-11'), 'accessories', '64.990', 'Tank bag'),
            $expense('EV23 KIA', self::day('2026-06-18'), 'tolls', '9.800', 'Péage A26'),
            // The Kia's rentals come from its lease agreement (seedFinance), never as expenses too.
        ])->saveData();
    }

    /**
     * The written-off Fiesta's settlement letter (spec.md §7.12): a one-page
     * PDF written under UPLOAD_PATH, shown on its *Written off* milestone.
     */
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
            $reading(self::day('2016-06-30'), '38400.000', 'Bought'),
            $reading(self::day('2025-11-20'), '131900.000', 'Written off'),
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
        $directory = $this->uploadPath() . '/attachments';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample paperwork skipped.</comment>');

            return;
        }
        $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($this->uploadPath() . '/' . $stored, $pdf);

        $fiesta = $this->vehicleIds()['WR14 FNE'];
        $this->table('attachments')->insert([
            'vehicle_id' => $fiesta,
            'owner_type' => 'sale',
            'owner_id' => $fiesta,
            'filename' => 'Settlement letter WR14 FNE.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($pdf),
            'stored_path' => $stored,
            'uploaded_at' => $now,
        ])->saveData();
    }

    /**
     * Valuations (spec.md §7.1): the Golf has one each spring since it was
     * bought (Phase 32), a part-exchange offer and online valuations, the
     * latest with a screenshot, so its *Ownership* card shows depreciation
     * and a value chart and every year of its true cost has some; the sold Fiesta
     * has one valuation before its sale, which its sale price overrides.
     * The bike's only valuation is 18 months old: a stale value, on its
     * Ownership card and in *Needs attention* (Phase 24). The Corolla's is
     * recent, for its PCP's equity (Phase 29.2).
     */
    private function seedValuations(string $now): void
    {
        $ids = $this->vehicleIds();
        $golf = $ids['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        $fiesta = $ids['WR14 FNE'] ?? throw new RuntimeException('The demo Fiesta is missing.');
        $corolla = $ids['LK22 VXN'] ?? throw new RuntimeException('The demo Corolla is missing.');
        $bike = $ids['MT20 BKE'] ?? throw new RuntimeException('The demo bike is missing.');
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
            // Phase 32: one each spring, so every year of the true cost trend has depreciation.
            $valuation($golf, self::day('2022-03-19'), '13000.000', 'Auto Trader valuation'),
            $valuation($golf, self::day('2023-03-11'), '12300.000', 'Auto Trader valuation'),
            $valuation($golf, self::day('2024-03-09'), '11900.000', 'Auto Trader valuation'),
            $valuation($golf, self::day('2025-03-08'), '11200.000', 'Part-exchange offer, Arnold Clark'),
            $valuation(
                $golf,
                self::day('2026-03-14'),
                '9800.000',
                'Auto Trader valuation',
                'Online, private sale, good condition',
            ),
            $valuation($fiesta, self::day('2025-10-02'), '2300.000', 'We Buy Any Car online valuation'),
            $valuation($bike, self::day('2025-03-28'), '6400.000', 'Part-exchange offer, Triumph dealer'),
            $valuation($corolla, self::day('2026-08-20'), '17800.000', 'Part-exchange offer, Toyota dealer'),
        ])->saveData();

        $latest = null;
        foreach ($this->fetchAll('SELECT id, vehicle_id, valued_on FROM vehicle_valuations') as $row) {
            if (
                is_array($row)
                && self::intValue($row['vehicle_id'] ?? null) === $golf
                && is_string($row['valued_on'] ?? null)
                && str_starts_with($row['valued_on'], self::day('2026-03-14'))
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
        $directory = $this->uploadPath() . '/attachments';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample valuation screenshot skipped.</comment>');

            return;
        }
        $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents($this->uploadPath() . '/' . $stored, $png);
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
     * Stations (Phase 30.1, spec.md §7.33): one per station text on the
     * fill-ups, as the upgrade makes them (home charging never one), with
     * brands, grades and positions on most; "Tesco Extra" and "Tesco Extra."
     * are one forecourt typed two ways, ready to merge from Duplicates.
     * Shell and Tesco Extra are the owner's favourites; Home and Work their
     * places.
     */
    private function seedStations(string $now, int $userId): void
    {
        $details = [
            'Tesco Extra' => ['Tesco', 'BT41 4LD', '54.718000', '-6.219000', ['e10_95', 'e5_97', 'b7']],
            'Tesco Extra.' => ['Tesco', null, '54.718300', '-6.219400', ['e10_95', 'b7']],
            'Shell' => ['Shell', 'BT41 1AA', '54.705000', '-6.240000', ['e10_95', 'e5_99', 'b7', 'b7_premium']],
            'Maxol Antrim' => ['Maxol', 'BT41 2BB', '54.712000', '-6.201000', ['e10_95', 'e5_97', 'b7']],
            'Applegreen M2' => ['Applegreen', null, '54.680000', '-6.150000', ['e10_95', 'b7', 'dc_rapid']],
            'Texaco Larne' => ['Texaco', 'BT40 1CC', null, null, ['e10_95', 'b7']],
            'Ionity' => ['Ionity', null, '54.660000', '-6.220000', ['dc_rapid', 'dc_ultra']],
            'Hotel car park' => [null, null, null, null, ['ac']],
        ];
        $texts = $this->fetchAll(
            "SELECT DISTINCT station FROM fuel_entries WHERE station IS NOT NULL AND (grade IS NULL OR grade <> 'home')",
        );
        foreach ($texts as $row) {
            $text = is_array($row) && is_string($row['station'] ?? null) ? $row['station'] : '';
            if ($text === '') {
                continue;
            }
            [$brand, $postcode, $lat, $lon, $grades] = $details[$text] ?? [null, null, null, null, []];
            $this->table('stations')->insert([
                'name' => $text,
                'brand' => $brand,
                'postcode' => $postcode,
                'country' => 'GB',
                'latitude' => $lat,
                'longitude' => $lon,
                'grades' => $grades === [] ? null : json_encode($grades, JSON_THROW_ON_ERROR),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
            $found = $this->query('SELECT id FROM stations WHERE name = ?', [$text]);
            $station = $found instanceof PDOStatement ? $found->fetch(PDO::FETCH_ASSOC) : false;
            $id = is_array($station) ? self::intValue($station['id'] ?? 0) : 0;
            $this->execute(
                "UPDATE fuel_entries SET station_id = ? WHERE station = ? AND (grade IS NULL OR grade <> 'home')",
                [$id, $text],
            );
            if (in_array($text, ['Shell', 'Tesco Extra'], true)) {
                $this->table('station_favourites')->insert([
                    'user_id' => $userId,
                    'station_id' => $id,
                    'created_at' => $now,
                ])->saveData();
            }
        }

        $this->table('places')->insert([
            ['user_id' => $userId, 'name' => 'Home', 'latitude' => '54.716000', 'longitude' => '-6.208000', 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $userId, 'name' => 'Work', 'latitude' => '54.597000', 'longitude' => '-5.930000', 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now],
        ])->saveData();
    }

    /**
     * Live fuel prices (Phase 30.2, spec.md §7.34): the sample provider
     * enabled, its made-up stations and prices near Home and Work (nothing
     * is fetched from anywhere), three of the demo's stations linked to it
     * with a daily listed price for the last 13 months (so the station
     * charts show both series and the Fuel tab can add up *Shopping
     * around*), and a price alert on the favourite Shell.
     */
    private function seedFuelPrices(string $now, int $userId): void
    {
        $at = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $refs = [];
        foreach (DemoPriceProvider::stations() as $station) {
            $this->table('provider_stations')->insert([
                'provider' => DemoPriceProvider::CODE,
                'provider_ref' => $station->ref,
                'name' => $station->name,
                'brand' => $station->brand,
                'address' => $station->address,
                'postcode' => $station->postcode,
                'latitude' => $station->latitude,
                'longitude' => $station->longitude,
                'opening_hours' => json_encode($station->openingHours, JSON_THROW_ON_ERROR),
                'amenities' => json_encode($station->amenities, JSON_THROW_ON_ERROR),
                'grades' => json_encode(array_map(static fn ($g): string => $g->value, $station->grades), JSON_THROW_ON_ERROR),
                'temporarily_closed' => $station->temporarilyClosed,
                'updated_at' => $now,
            ])->saveData();
            $found = $this->query(
                'SELECT id FROM provider_stations WHERE provider = ? AND provider_ref = ?',
                [DemoPriceProvider::CODE, $station->ref],
            );
            $row = $found instanceof PDOStatement ? $found->fetch(PDO::FETCH_ASSOC) : false;
            $refs[$station->ref] = is_array($row) ? self::intValue($row['id'] ?? 0) : 0;
        }
        $prices = [];
        foreach (DemoPriceProvider::prices($at) as $price) {
            $prices[] = [
                'provider_station_id' => $refs[$price->ref],
                'grade' => $price->grade->value,
                'price' => $price->price,
                'reported_at' => $price->reportedAt->format('Y-m-d H:i:s'),
                'synced_at' => $now,
            ];
        }
        $this->table('provider_prices')->insert($prices)->saveData();

        // Three of the demo's own stations are in the feed: Tesco Extra, the
        // favourite Shell and Maxol Antrim.
        $links = ['Tesco Extra' => 'demo-3', 'Shell' => 'demo-4', 'Maxol Antrim' => 'demo-2'];
        foreach ($links as $name => $ref) {
            $this->execute(
                'UPDATE stations SET provider = ?, provider_ref = ? WHERE name = ?',
                [DemoPriceProvider::CODE, $ref, $name],
            );
            [, , , , , $grades, $offset] = DemoPriceProvider::STATIONS[$ref];
            $changes = [];
            for ($day = 400; $day >= 0; $day--) {
                $when = $at->modify(sprintf('-%d days', $day))->setTime(7, 0);
                if ($when > $at) {
                    continue;
                }
                // A gentle wander over the year, a few pence either way.
                $drift = (int) round(40 * sin($day / 45) + 10 * sin($day / 7));
                foreach ($grades as $code) {
                    $pence = (int) round((float) DemoPriceProvider::BASE[$code] * 1000) + $offset * 10 + $drift;
                    $changes[] = [
                        'provider' => DemoPriceProvider::CODE,
                        'provider_ref' => $ref,
                        'grade' => $code,
                        'price' => number_format($pence / 1000, 3, '.', ''),
                        'reported_at' => $when->format('Y-m-d H:i:s'),
                    ];
                }
            }
            foreach (array_chunk($changes, 200) as $chunk) {
                $this->table('listed_price_changes')->insert($chunk)->saveData();
            }
        }

        $shell = $this->fetchRow("SELECT id FROM stations WHERE name = 'Shell'");
        $shellId = is_array($shell) ? self::intValue($shell['id'] ?? 0) : 0;
        if ($shellId > 0) {
            $this->table('price_alerts')->insert([
                'user_id' => $userId,
                'station_id' => $shellId,
                'grade' => 'e10_95',
                'below' => '1.349',
                'triggered_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
        }

        $this->table('settings')->insert([
            [
                'scope' => 'global',
                'owner_id' => 0,
                'name' => 'fuel_prices',
                'value' => json_encode(
                    ['provider' => DemoPriceProvider::CODE, 'refresh' => 60, 'e5' => 'e5_97'],
                    JSON_THROW_ON_ERROR,
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scope' => 'global',
                'owner_id' => 0,
                'name' => 'fuel_prices.sync',
                'value' => json_encode([
                    'provider' => DemoPriceProvider::CODE,
                    'last_good' => $at->format(DATE_ATOM),
                    'last_full' => $at->format(DATE_ATOM),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    /**
     * Finance agreements (Phase 29.2, spec.md §7.32):
     *
     * - the leased Kia: an initial rental of six months, then 35 rentals,
     *   in euros, its rentals counted from the agreement (no expenses too);
     * - the Corolla: a 48-month PCP at 6.9% with an optional final payment,
     *   8,000 mi a year at 9p, heading about 1,200 mi over (its odometer at
     *   the start is set from its readings so the projection lands there,
     *   whatever the random mileage), with a recent valuation for equity;
     * - the written-off Fiesta: an HP settled early in 2018 with the
     *   lender's settlement quote.
     */
    private function seedFinance(string $now, int $userId): void
    {
        $ids = $this->vehicleIds();
        $agreement = static fn (array $values): array => array_merge([
            'vehicle_id' => 0,
            'created_by' => $userId,
            'type' => 'hp',
            'lender' => '',
            'agreement_number' => null,
            'status' => 'active',
            'started_on' => '',
            'first_payment_on' => '',
            'number_of_payments' => 0,
            'regular_payment' => '0',
            'first_payment' => null,
            'final_payment' => null,
            'final_payment_on' => null,
            'cash_price' => null,
            'customer_deposit' => '0',
            'dealer_contribution' => '0',
            'initial_rental' => null,
            'amount_of_credit' => null,
            'total_amount_payable' => null,
            'apr' => '0',
            'documentation_fee' => null,
            'option_to_purchase_fee' => null,
            'annual_mileage_allowance' => null,
            'mileage_unit' => 'mi',
            'excess_mileage_charge' => null,
            'start_odometer_km' => null,
            'count_in_costs' => true,
            'ended_on' => null,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);

        $this->table('finance_agreements')->insert([
            $agreement([
                'vehicle_id' => $ids['EV23 KIA'], 'type' => 'lease', 'lender' => 'Kia Lease (Ayvens)',
                'agreement_number' => 'KL-2024-118734',
                'started_on' => self::day('2024-02-10'), 'first_payment_on' => self::day('2024-03-10'),
                'number_of_payments' => 35, 'regular_payment' => '449.000', 'initial_rental' => '2694.000',
                'documentation_fee' => '250.000',
                'annual_mileage_allowance' => 16000, 'mileage_unit' => 'km', 'excess_mileage_charge' => '0.0800',
                // Leased new: the odometer started at nothing.
                'start_odometer_km' => '0.000',
            ]),
            $agreement([
                'vehicle_id' => $ids['LK22 VXN'], 'type' => 'pcp', 'lender' => 'Toyota Financial Services',
                'agreement_number' => 'TFS-0045519203',
                'started_on' => self::day('2024-04-01'), 'first_payment_on' => self::day('2024-05-01'),
                'number_of_payments' => 47, 'regular_payment' => '284.710',
                'final_payment' => '10450.000',
                'cash_price' => '22995.000', 'customer_deposit' => '2500.000', 'dealer_contribution' => '750.000',
                'apr' => '6.900', 'option_to_purchase_fee' => '10.000',
                'annual_mileage_allowance' => 8000, 'mileage_unit' => 'mi', 'excess_mileage_charge' => '0.0900',
                'start_odometer_km' => $this->pcpStartOdometer($ids['LK22 VXN'], self::day('2028-04-01'), 8000 * 4 + 1200),
            ]),
            $agreement([
                'vehicle_id' => $ids['WR14 FNE'], 'type' => 'hp', 'lender' => 'Ford Credit',
                'status' => 'settled', 'ended_on' => self::day('2018-11-15'),
                'started_on' => self::day('2016-06-30'), 'first_payment_on' => self::day('2016-07-30'),
                'number_of_payments' => 48, 'regular_payment' => '133.310',
                'cash_price' => '6500.000', 'customer_deposit' => '1000.000', 'apr' => '7.900',
                'notes' => 'Settled early with a bonus from work.',
            ]),
        ])->saveData();

        $fiesta = null;
        foreach ($this->fetchAll(sprintf('SELECT id FROM finance_agreements WHERE vehicle_id = %d', $ids['WR14 FNE'])) as $row) {
            $fiesta = is_array($row) ? self::intValue($row['id'] ?? null) : null;
        }
        if ($fiesta === null) {
            throw new RuntimeException('The demo Fiesta agreement was not created.');
        }
        $this->table('settlement_quotes')->insert([
            'agreement_id' => $fiesta, 'quoted_on' => self::day('2018-11-01'), 'amount' => '2541.370',
            'valid_until' => self::day('2018-11-29'),
            'notes' => 'Phoned Ford Credit', 'created_at' => $now,
        ])->saveData();
        $this->table('finance_payment_events')->insert([
            'agreement_id' => $fiesta, 'due_on' => null, 'kind' => 'settlement', 'amount' => '2541.370',
            'paid_on' => self::day('2018-11-15'), 'notes' => null, 'created_at' => $now,
        ])->saveData();
    }

    /**
     * The odometer at a PCP's start (km) that puts its projected distance at
     * the end on $miles, by §7.4's projection from the vehicle's readings:
     * the latest reading plus the average daily distance (first to latest)
     * times the days left (spec.md §7.32 *Mileage*).
     */
    private function pcpStartOdometer(int $vehicle, string $endsOn, int $miles): string
    {
        $readings = [];
        $rows = $this->fetchAll(sprintf('SELECT reading_km, recorded_at FROM odometer_readings WHERE vehicle_id = %d', $vehicle));
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['reading_km'] ?? null) && is_string($row['recorded_at'] ?? null)) {
                $readings[] = [(int) strtotime(substr($row['recorded_at'], 0, 19) . ' UTC'), (float) $row['reading_km']];
            }
        }
        usort($readings, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        if (count($readings) < 2) {
            throw new RuntimeException('The demo PCP needs the vehicle\'s readings first.');
        }
        [$firstAt, $firstKm] = $readings[0];
        [$latestAt, $latestKm] = $readings[count($readings) - 1];
        $perDay = ($latestKm - $firstKm) / max(1, ($latestAt - $firstAt) / 86400);
        $latestDay = (int) strtotime(gmdate('Y-m-d', $latestAt) . ' UTC');
        $days = max(0, intdiv((int) strtotime($endsOn . ' UTC') - $latestDay, 86400));
        $start = $latestKm + $perDay * $days - $miles * 1.609344;

        return number_format(round(max(0.0, $start)), 3, '.', '');
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
        $this->table('mileage_rate_sets')->insert([
            $rates(self::day('2011-04-06'), '0.4500'),
            $rates(self::day('2026-04-06'), '0.5500'),
        ])->saveData();

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
        $day = new DateTimeImmutable(self::day('2025-04-15'));
        $last = new DateTimeImmutable(self::day('2026-09-25'));
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
        foreach ([self::day('2025-08-02') => 'Portrush', self::day('2026-07-18') => 'Newcastle'] as $on => $to) {
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
        $directory = $this->uploadPath() . '/attachments';
        if ($toll === null || (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))) {
            $this->getOutput()->writeln('<comment>UPLOAD_PATH is not writable; sample toll receipt skipped.</comment>');

            return;
        }
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );
        $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents($this->uploadPath() . '/' . $stored, $png);
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
        $directory = $this->uploadPath() . '/attachments';
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
            file_put_contents($this->uploadPath() . '/' . $stored, $contents);
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
            'recorded_at' => self::localNoon(self::day('2021-03-14')),
            'source' => 'manual',
            'note' => 'On collection from the dealer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $attach('odometer', $photo, 'Dashboard 2021-03-14.png', $png, 'image/png');

        $this->table('attachments')->insert($attachments)->saveData();
    }

    /**
     * Incidents (Phase 27.1, spec.md §7.29): on the Golf, a 2024 parked
     * scrape that was not the owner's fault, claimed and settled, with its
     * bumper repair linked, two photos and the other party's insurer; and
     * the pothole behind August's damaged tyre, not claimed, linked to that
     * tyre change. The scrape's repair estimate is recorded (Phase 27.2),
     * information only. On the Fiesta, an at-fault collision in October 2025
     * settled as a Cat S total loss: the car was archived as *Written off*,
     * the settlement its sale price, so the claims history lists a vehicle
     * no longer owned and its ownership counts the settlement once.
     */
    private function seedIncidents(string $now, int $userId): void
    {
        $ids = $this->vehicleIds();
        $golf = $ids['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        $fiesta = $ids['WR14 FNE'] ?? throw new RuntimeException('The demo Fiesta is missing.');

        $scrape = $this->incidentRow($golf, $now, $userId, [
            'occurred_on' => self::day('2024-06-12'),
            'occurred_at_time' => '17:40',
            'location' => 'Abbey Centre car park, Newtownabbey',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'description' => 'Reversed into while parked; the other driver left a note.',
            'damage_areas' => '["rear","left"]',
            'severity' => 'minor',
            'other_party_name' => 'J. Morrow',
            'other_party_registration' => 'KX17 ABC',
            'other_party_insurer' => 'Admiral',
            'closed_on' => self::day('2024-07-30'),
            'claim_status' => 'settled',
            'insurer' => 'Admiral',
            'claim_number' => 'ADM-2406-118734',
            'excess' => '0.000',
            'payout' => '640.000',
            'ncd_affected' => 'no',
            'claim_updated_on' => self::day('2024-07-30'),
            'repair_estimate' => '655.000',
            'notes' => 'Estimate from Smart Repair Belfast',
        ]);
        $this->insertRow('maintenance_entries', [
            'vehicle_id' => $golf,
            'performed_on' => self::day('2024-06-24'),
            'category' => 'bodywork',
            'title' => 'Rear bumper and quarter panel repair',
            'description' => 'Bumper reshaped and painted, scuff on the left quarter blended.',
            'cost' => '640.000',
            'vendor' => 'Smart Repair Belfast',
            'odometer_km' => null,
            'created_by' => $userId,
            'incident_id' => $scrape,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pothole = $this->incidentRow($golf, $now, $userId, [
            'occurred_on' => self::day('2026-08-21'),
            'location' => 'A6, near Antrim',
            'type' => 'pothole',
            'damage_areas' => '["wheels"]',
            'severity' => 'minor',
            'description' => 'Hit a pothole in the rain; the rear left tyre bulged.',
            'closed_on' => self::day('2026-08-22'),
        ]);
        foreach ($this->fetchAll('SELECT id, vehicle_id, done_on, kind FROM tyre_changes') as $row) {
            if (
                is_array($row)
                && self::intValue($row['vehicle_id'] ?? null) === $golf
                && substr(self::stringValue($row['done_on'] ?? ''), 0, 10) === self::day('2026-08-22')
            ) {
                $this->execute('UPDATE tyre_changes SET incident_id = ? WHERE id = ?', [$pothole, self::intValue($row['id'])]);
            }
        }

        $totalLoss = $this->incidentRow($fiesta, $now, $userId, [
            'occurred_on' => self::day('2025-10-28'),
            'occurred_at_time' => '08:10',
            'location' => 'Doagh Road roundabout',
            'type' => 'collision',
            'fault' => 'at_fault',
            'description' => 'Ran into the back of a van in slow traffic.',
            'damage_areas' => '["front","underside"]',
            'severity' => 'major',
            'driver_user_id' => $userId,
            'closed_on' => self::day('2025-11-20'),
            'write_off_category' => 'cat_s',
            'claim_status' => 'settled',
            'insurer' => 'Direct Line',
            'claim_number' => 'DL-2510-0457',
            'excess' => '250.000',
            'payout' => '2100.000',
            'ncd_affected' => 'yes',
            'claim_updated_on' => self::day('2025-11-18'),
        ]);
        $this->execute('UPDATE vehicles SET disposal_incident_id = ? WHERE id = ?', [$totalLoss, $fiesta]);

        // Two photos of the scrape: kept as taken (spec.md §7.12).
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );
        $directory = $this->uploadPath() . '/attachments';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        foreach (['Rear bumper.png', 'Left quarter.png'] as $name) {
            $stored = 'attachments/' . bin2hex(random_bytes(16)) . '.png';
            file_put_contents($this->uploadPath() . '/' . $stored, $png);
            $this->insertRow('attachments', [
                'vehicle_id' => $golf,
                'owner_type' => 'incident',
                'owner_id' => $scrape,
                'filename' => $name,
                'mime' => 'image/png',
                'size' => strlen($png),
                'stored_path' => $stored,
                'uploaded_at' => $now,
                'uploaded_by' => $userId,
            ]);
        }
    }

    /**
     * One incident row, every column in the same order (Phinx inserts by position).
     *
     * @param array<string, mixed> $values
     */
    private function incidentRow(int $vehicle, string $now, int $userId, array $values): int
    {
        return $this->insertRow('incidents', array_merge([
            'vehicle_id' => $vehicle,
            'created_by' => $userId,
            'occurred_on' => '',
            'occurred_at_time' => null,
            'location' => null,
            'type' => 'other',
            'fault' => 'unknown',
            'description' => null,
            'damage_areas' => '[]',
            'severity' => null,
            'driver_user_id' => $userId,
            'driver_name' => null,
            'other_party_name' => null,
            'other_party_registration' => null,
            'other_party_insurer' => null,
            'police_reference' => null,
            'status' => 'closed',
            'closed_on' => null,
            'write_off_category' => 'none',
            'notes' => null,
            'claim_status' => 'not_claimed',
            'insurer' => null,
            'insurance_document_id' => null,
            'claim_number' => null,
            'excess' => null,
            'payout' => null,
            'ncd_affected' => 'unknown',
            'claim_updated_on' => null,
            'repair_estimate' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values));
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
    /**
     * The Golf's issues (Phase 40.1, spec.md §7.37): a knock noticed three
     * weeks ago and still open, with a note; an MOT-style advisory being
     * watched, looked at again in three months; and grinding brakes fixed by
     * the June brake pads.
     */
    private function seedIssues(string $now, int $userId): void
    {
        $golf = $this->vehicleIds()['LB19 KTR'] ?? throw new RuntimeException('The demo Golf is missing.');
        // Open: a knock, with its reading and a later note.
        $noticed = self::day('2026-09-15');
        $km = $this->odometerOn($golf, $noticed);
        $knock = $this->issueRow($golf, $now, $userId, [
            'noticed_on' => $noticed,
            'odometer_km' => $km,
            'title' => 'Knock from front left over bumps',
            'description' => 'A dull knock from the front left wheel over speed bumps, worse when cold. Not under braking.',
            'category' => 'repair',
        ]);
        $this->table('odometer_readings')->insert([
            'vehicle_id' => $golf, 'reading_km' => $km, 'recorded_at' => self::localNoon($noticed),
            'source' => 'issue', 'issue_id' => $knock, 'created_at' => $now, 'updated_at' => $now,
        ])->saveData();
        $this->issueLine($now, $userId, $knock, self::day('2026-09-29'), ['note' => 'Still there, and now on the drive too.']);

        // Watching: an advisory, looked at again in three months.
        $advised = self::day('2026-08-05');
        $pipes = $this->issueRow($golf, $now, $userId, [
            'noticed_on' => $advised,
            'title' => 'Advisory: brake pipes corroded',
            'description' => 'Rear brake pipes slightly corroded (advisory at the garage).',
            'category' => 'brakes',
            'status' => 'watching',
            'look_again_on' => self::day('2027-01-04'),
        ]);
        $watch = ['status_from' => 'open', 'status_to' => 'watching', 'reason' => 'watch'];
        $this->issueLine($now, $userId, $pipes, $advised, $watch);

        // Fixed: grinding brakes, fixed by the June brake pads.
        $row = $this->fetchRow(sprintf(
            "SELECT id, performed_on FROM maintenance_entries WHERE vehicle_id = %d AND title = 'Front brake pads'",
            $golf,
        ));
        $pads = is_array($row) ? self::intValue($row['id'] ?? $row[0] ?? 0) : 0;
        $fixedOn = self::day('2026-06-18');
        $grinding = $this->issueRow($golf, $now, $userId, [
            'noticed_on' => self::day('2026-06-02'),
            'title' => 'Grinding from the front brakes',
            'category' => 'brakes',
            'status' => 'fixed',
            'fixed_on' => $fixedOn,
            'status_before_fix' => 'open',
        ]);
        if ($pads > 0) {
            $this->insertRow('issue_fixes', ['issue_id' => $grinding, 'maintenance_entry_id' => $pads, 'created_at' => $now]);
        }
        $fixed = ['status_from' => 'open', 'status_to' => 'fixed', 'reason' => 'fixed'];
        $this->issueLine($now, $userId, $grinding, $fixedOn, $fixed);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function issueRow(int $vehicle, string $now, int $userId, array $values): int
    {
        return $this->insertRow('issues', $values + [
            'vehicle_id' => $vehicle,
            'created_by' => $userId,
            'description' => null,
            'category' => null,
            'status' => 'open',
            'affects_safety' => false,
            'look_again_on' => null,
            'look_again_km' => null,
            'fixed_on' => null,
            'status_before_fix' => null,
            'source' => 'manual',
            'source_ref' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function issueLine(string $now, int $userId, int $issueId, string $on, array $values): int
    {
        return $this->insertRow('issue_updates', $values + [
            'issue_id' => $issueId,
            'noted_on' => $on,
            'odometer_km' => null,
            'note' => null,
            'status_from' => null,
            'status_to' => null,
            'reason' => null,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

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
        $repairOn = self::day('2026-05-02');
        $repairKm = $this->odometerOn($golf, $repairOn);
        $repair = $this->serviceRecord($golf, $repairOn, $repairKm, 'Tyre repair, front right', '25.000', 'Kwik Fit', $now);
        $bikeRearOn = self::day('2026-08-10');
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
            [$golf, TyreChangeKind::Existing, self::day('2025-09-28'), null, [
                ...$depths(array_map($on, $s, $road), ['4.2', '4.1', '6.2', '6.2']),
            ], []],
            [$golf, TyreChangeKind::Fit, self::day('2025-11-08'), null, [
                ...$depths(array_map($off, $s, $road), ['4.0', '3.9', '6.0', '6.0']),
                ...$depths(array_map($on, $w, $road), ['7.0', '7.0', '7.2', '7.1']),
            ], []],
            [$golf, TyreChangeKind::Swap, self::day('2026-03-08'), null, [
                ...$depths(array_map($off, $w, $road), ['6.2', '6.3', '6.6', '6.6']),
                ...$depths(array_map($on, $s, $road), ['4.0', '3.9', '6.0', '6.0']),
            ], []],
            [$golf, TyreChangeKind::Fit, self::day('2026-03-10'), $record, [
                $retire($s[0], $fl), $retire($s[1], $fr), ...$depths([$on($f[0], $fl), $on($f[1], $fr)], ['8.0', '8.0']),
            ], [$s[0] => 'worn', $s[1] => 'worn']],
            [$golf, TyreChangeKind::Check, self::day('2026-04-20'), null, [
                $measure($f[0], $fl, '7.7'), $measure($f[1], $fr, '7.7'),
                $measure($s[2], $rl, '5.5'), $measure($s[3], $rr, '5.6'),
            ], []],
            [$golf, TyreChangeKind::Repair, $repairOn, $repair, [new TyreChangeLine($f[1], TyreLineAction::Repair, $fr)], []],
            [$golf, TyreChangeKind::Check, self::day('2026-06-20'), null, [
                $measure($f[0], $fl, '7.1'), $measure($f[1], $fr, '7.2'),
                $measure($s[2], $rl, '4.7'), $measure($s[3], $rr, '4.8'),
            ], []],
            [$golf, TyreChangeKind::Rotate, self::day('2026-07-12'), null, [
                $move($f[0], $rl), $move($f[1], $rr), $move($s[2], $fl), $move($s[3], $fr),
            ], []],
            [$golf, TyreChangeKind::Fit, self::day('2026-08-22'), null, [
                $retire($f[0], $rl)->withTread('6.4'), $on($f[2], $rl)->withTread('8.0'),
            ], [$f[0] => 'damaged']],
            [$golf, TyreChangeKind::Check, self::day('2026-09-20'), null, [
                $measure($s[2], $fl, '3.3'), $measure($s[3], $fr, '3.5'),
                $measure($f[2], $rl, '7.9'), $measure($f[1], $rr, '6.3'),
            ], [], '78700.000'],
            [$bike, TyreChangeKind::Existing, self::day('2026-03-20'), null, [
                $on($bikeFront, TyrePosition::Front)->withTread('3.1'), $on($bikeRear[0], TyrePosition::Rear)->withTread('2.4'),
            ], []],
            [$bike, TyreChangeKind::Fit, $bikeRearOn, $bikeRecord, [
                $retire($bikeRear[0], TyrePosition::Rear)->withTread('1.6'),
                $on($bikeRear[1], TyrePosition::Rear)->withTread('6.0'),
            ], [$bikeRear[0] => 'worn']],
            [$bike, TyreChangeKind::Check, self::day('2026-09-19'), null, [
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
                // Phase 30.1: a handful of forecourts, one of them typed two ways (ready to merge).
                default => $fuel === 'ev' ? 'Home' : self::STATION_ROTA[$i % count(self::STATION_ROTA)],
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
     * The Golf's fill-ups before September 2025 (Phase 32, spec.md §7.35):
     * one full tank a month from April 2021, on the odometer readings it
     * already has, the last at 61,155 km where the recent ones start, so no
     * tank reads oddly. Deterministic (no mt_rand()), so every other demo
     * figure stays put. The years differ on purpose, for *What changed*:
     * 2024 is driven less (3,600 km to the end of September against 5,800
     * from April to December 2023), costs about 5% more a litre and uses
     * about 3% less fuel. Ungraded: they predate grades.
     *
     * @return list<array<string, mixed>>
     */
    private static function golfEarlierYears(int $golf, string $now): array
    {
        // Odometer anchors: its existing readings, plus where 2023 ends (the year it was driven more).
        $anchors = [
            self::day('2021-03-14') => 31200.0,
            self::day('2022-03-20') => 38900.0,
            self::day('2023-03-18') => 45600.0,
            self::day('2023-12-31') => 51400.0,
            self::day('2024-10-01') => 55000.0,
            self::day('2025-03-06') => 57800.0,
            self::day('2025-09-14') => 61155.0,
        ];
        // Price a litre and km a litre, by year.
        $years = [2021 => [1.36, 15.4], 2022 => [1.62, 15.4], 2023 => [1.47, 15.2], 2024 => [1.54, 15.7], 2025 => [1.42, 15.9]];
        $kmOn = static function (int $time) use ($anchors): float {
            $previous = [0, 0.0];
            foreach ($anchors as $date => $km) {
                $at = (int) strtotime($date . ' 00:00 UTC');
                if ($at >= $time && $previous[0] > 0) {
                    return $previous[1] + ($km - $previous[1]) * ($time - $previous[0]) / ($at - $previous[0]);
                }
                $previous = [$at, $km];
            }

            return $previous[1];
        };

        $rows = [];
        $last = 31200.0;
        $dates = [];
        $end = (int) strtotime(self::day('2025-09-01') . ' UTC');
        $first = (int) strtotime(self::day('2021-04-10') . ' 08:00 UTC');
        for ($month = $first; $month < $end; $month = (int) strtotime('+1 month', $month)) {
            $dates[] = $month;
        }
        $dates[] = (int) strtotime(self::day('2025-09-14') . ' 08:00 UTC');
        foreach ($dates as $i => $time) {
            $km = $kmOn((int) strtotime(gmdate('Y-m-d', $time) . ' 00:00 UTC'));
            // The year the row was authored for, not the (shifted) one it lands in.
            $year = min(2025, max(2021, (int) gmdate('Y', $time - self::$shiftDays * 86400)));
            [$price, $kmPerLitre] = $years[$year];
            // A little more fuel in winter, and a price that moves a little month to month.
            $season = 1 + 0.05 * cos(2 * M_PI * ((int) gmdate('z', $time) - 14) / 365.25);
            $volume = round(($km - $last) / $kmPerLitre * $season, 3);
            $unitPrice = round($price + 0.02 * sin($i / 3), 3);
            $rows[] = [
                'vehicle_id' => $golf,
                'filled_at' => gmdate('Y-m-d H:i:s', $time),
                'odometer_km' => number_format($km, 3, '.', ''),
                'fuel' => 'petrol',
                'grade' => null,
                'volume' => number_format($volume, 3, '.', ''),
                'price_per_unit' => number_format($unitPrice, 6, '.', ''),
                'total_cost' => number_format(round($volume * $unitPrice, 2), 3, '.', ''),
                'is_partial' => false,
                'is_missed_previous' => false,
                'station' => self::STATION_ROTA[$i % count(self::STATION_ROTA)],
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $last = $km;
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
        $start = (int) strtotime(self::day('2026-01-10') . ' 00:00 UTC');
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

    private function sampleUsersExist(): bool
    {
        $row = $this->fetchRow(
            "SELECT COUNT(*) AS n FROM users WHERE username IN ('" . self::USERNAME . "', '" . self::PARTNER . "')",
        );

        return is_array($row) && self::intValue($row['n'] ?? $row[0] ?? 0) > 0;
    }

    /**
     * The password in $variable (bin/dev-setup.sh sets it), or a new random
     * one: 20 characters from an unambiguous alphabet.
     */
    private static function passwordFrom(string $variable): string
    {
        $given = getenv($variable);
        if (is_string($given) && $given !== '') {
            return $given;
        }
        $password = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $last)];
        }

        return $password;
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
