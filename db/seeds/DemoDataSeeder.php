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

        $this->getOutput()->writeln(sprintf(
            '<info>Sample data added. Sign in as "%s" with password "%s".</info>',
            self::USERNAME,
            self::PASSWORD,
        ));
    }

    /**
     * Drivers return integers as int or numeric string.
     */
    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
