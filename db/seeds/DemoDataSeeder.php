<?php

declare(strict_types=1);

use Logbook\Kernel;
use Phinx\Seed\AbstractSeed;

/**
 * Sample data for local development: a demo owner and a small garage (the
 * vehicles from the design mock-ups, one of them sold and archived).
 *
 *   bin/dev seed
 *   vendor/bin/phinx seed:run -e development -s DemoDataSeeder
 *
 * Sign in as `demo` / `logbook-demo`. Refuses to run in production, and on a
 * database that already has an account (Logbook has a single owner): reset
 * first with `bin/dev reset`.
 */
final class DemoDataSeeder extends AbstractSeed
{
    public const string USERNAME = 'demo';
    public const string PASSWORD = 'logbook-demo';

    public function run(): void
    {
        if (Kernel::settings()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder is for development only (APP_ENV=production).');
        }

        $existing = $this->fetchRow('SELECT COUNT(*) AS n FROM users');
        if (is_array($existing) && self::intValue($existing['n'] ?? $existing[0] ?? 0) > 0) {
            $this->getOutput()->writeln(
                '<comment>An account already exists; sample data not added. Reset the database first (bin/dev reset).</comment>',
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
            'nickname' => null,
            'year' => null,
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
                'capacity' => '50.000', 'purchase_date' => '2021-03-14', 'purchase_price' => '14250.000',
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
                'registration' => 'EV23 KIA', 'fuel_type' => 'ev', 'capacity' => '77.400', 'currency' => 'EUR',
                'purchase_date' => '2024-02-10', 'purchase_price' => '0.000',
            ]),
            $vehicle([
                'type' => 'car', 'make' => 'Ford', 'model' => 'Fiesta 1.0 EcoBoost', 'year' => 2014,
                'registration' => 'WR14 FNE', 'fuel_type' => 'petrol', 'capacity' => '42.000',
                'purchase_date' => '2016-06-30', 'purchase_price' => '6500.000',
                'sale_date' => '2025-11-20', 'sale_price' => '2100.000',
                'status' => 'archived', 'archived_at' => '2025-11-20 12:00:00',
            ]),
        ])->saveData();

        $this->seedFuel($now);

        $this->getOutput()->writeln(sprintf(
            '<info>Sample data added. Sign in as "%s" with password "%s".</info>',
            self::USERNAME,
            self::PASSWORD,
        ));
    }

    /**
     * A year of fill-ups (with partial fills, one missed fill-up and EV
     * charges), each with its odometer reading, plus manual readings for the
     * hybrid. Deterministic, so every reset looks the same.
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

        $entries = [
            // Golf: ~45 mpg (UK), fill every ~12 days; partial every 6th; one fill-up never logged.
            ...$this->fillUps($ids['LB19 KTR'], '2025-09-20', 30, 61155.0, 540.0, 15.9, 1.479, 'petrol', 6, 17, $now),
            ...$this->fillUps($ids['MT20 BKE'], '2026-03-15', 12, 18500.0, 230.0, 19.5, 1.529, 'petrol', 0, null, $now),
            // EV6: charges in kWh, ~5.6 km/kWh, most of them partial.
            ...$this->fillUps($ids['EV23 KIA'], '2026-01-05', 24, 21000.0, 260.0, 5.6, 0.285, 'ev', -3, null, $now),
        ];
        $this->table('fuel_entries')->insert($entries)->saveData();

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
     * Simulated fill-ups: the tank's deficit since the last full fill is what
     * a full fill adds, so full-to-full economy comes out at $kmPerUnit.
     *
     * @param int $partialEvery every Nth fill is partial (0: none; negative: all but every Nth)
     * @param int|null $missed index of a fill-up that happens but is never logged
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
    ): array {
        $rows = [];
        $deficit = 0.0;
        $time = (int) strtotime($start . ' 08:00 UTC');
        $afterGap = false;

        for ($i = 0; $i < $count; $i++) {
            $distance = $kmPerFill * (0.8 + mt_rand(0, 400) / 1000);
            $km += $distance;
            $deficit += $distance / ($kmPerUnit * (0.93 + mt_rand(0, 140) / 1000));
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
            $rows[] = [
                'vehicle_id' => $vehicleId,
                'filled_at' => gmdate('Y-m-d H:i:s', $time),
                'odometer_km' => number_format($km, 3, '.', ''),
                'fuel' => $fuel,
                'volume' => number_format($volume, 3, '.', ''),
                'price_per_unit' => number_format($unitPrice, 6, '.', ''),
                'total_cost' => number_format(round($volume * $unitPrice, 2), 3, '.', ''),
                'is_partial' => $partial,
                'is_missed_previous' => $afterGap,
                'station' => $fuel === 'ev' ? 'Home' : ($i % 3 === 0 ? 'Tesco Extra' : 'Shell'),
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $afterGap = false;
        }

        return $rows;
    }

    /**
     * Drivers return integers as int or numeric string.
     */
    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
