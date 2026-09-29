<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeZone;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Support\Database\Row;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 11.2's tread depth: upgrading gives US-gallon owners 32nds and
 * everyone else millimetres; rolling back keeps the mileage of every
 * *Check tread* (its reading becomes a manual one) before the checks, tyre
 * reminders and thresholds an older version cannot read are removed.
 */
final class TreadDepthMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the tread depth. */
    private const string BEFORE = '20261006100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testUsGallonOwnersGetThirtySecondsOnly(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $connection = $this->connection($app);
        foreach (['uk' => 'gal_uk', 'metric' => 'l', 'us' => 'gal_us'] as $name => $volume) {
            $connection->insert('users', [
                'username' => $name,
                'password_hash' => 'x',
                'display_name' => $name,
                'locale' => 'en_GB',
                'timezone' => 'UTC',
                'distance_unit' => 'mi',
                'volume_unit' => $volume,
                'consumption_unit' => 'mpg_uk',
                'currency' => 'GBP',
                'theme' => 'system',
                'accent' => 'blue',
                'created_at' => '2026-09-29 10:00:00',
                'updated_at' => '2026-09-29 10:00:00',
            ]);
        }

        Migrator::run('migrate');
        $units = [];
        foreach ($connection->fetchAllAssociative('SELECT username, depth_unit FROM users') as $row) {
            $units[Row::string($row, 'username')] = Row::string($row, 'depth_unit');
        }
        ksort($units);
        self::assertSame(['metric' => 'mm', 'uk' => 'mm', 'us' => 'in32'], $units);
    }

    public function testRollingBackKeepsTheMileageOfATreadCheck(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $zone = new DateTimeZone('Europe/London');
        $changes = $this->service($app, TyreChangeService::class);
        $fitted = LocalTime::parseDate('2026-06-01');
        $checked = LocalTime::parseDate('2026-09-01');
        assert($fitted !== null && $checked !== null);
        $existing = $changes->existing(
            $golf,
            new TyreChangeData($fitted, '40000.000'),
            [new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'), '7.000')],
            $zone,
            'en_GB',
        );
        $changes->check($golf, new TyreChangeData($checked, '45000.000'), [$existing->tyreIds()[0] => '6.000'], $zone, 'en_GB');
        $connection = $this->connection($app);
        $connection->insert('reminders', [
            'vehicle_id' => $golf->id,
            'source' => 'tyre',
            'source_id' => $golf->id,
            'occurrence' => '2',
            'category' => 'tyres',
            'title' => 'Tyres: front left due in about 800 mi',
            'due_on' => '2026-12-01',
            'lead_time_days' => 30,
            'status' => 'upcoming',
            'channels_notified' => '[]',
            'created_at' => '2026-09-29 10:00:00',
            'updated_at' => '2026-09-29 10:00:00',
        ]);
        $connection->insert('settings', [
            'scope' => 'user',
            'owner_id' => $owner->id,
            'name' => 'tyres.thresholds',
            'value' => '{"age_years": 5}',
            'created_at' => '2026-09-29 10:00:00',
            'updated_at' => '2026-09-29 10:00:00',
        ]);

        Migrator::run('rollback', ['--target' => self::BEFORE]);

        $readings = $connection->fetchAllAssociative(
            'SELECT source, reading_km FROM odometer_readings WHERE vehicle_id = ? ORDER BY reading_km',
            [$golf->id],
        );
        self::assertSame(
            ['tyre 40000.000', 'manual 45000.000'],
            array_map(
                static fn (array $r): string => Row::string($r, 'source') . ' ' . Row::decimal($r, 'reading_km', 3),
                $readings,
            ),
            'the check’s reading is kept as a manual one',
        );
        self::assertSame(['existing'], $connection->fetchFirstColumn('SELECT kind FROM tyre_changes'));
        self::assertSame(['on'], $connection->fetchFirstColumn('SELECT action FROM tyre_change_lines'));
        self::assertSame([], $connection->fetchFirstColumn('SELECT id FROM reminders'));
        self::assertSame([], $connection->fetchFirstColumn('SELECT id FROM settings WHERE name = ?', ['tyres.thresholds']));

        Migrator::run('migrate');
        self::assertSame('mm', $connection->fetchOne('SELECT depth_unit FROM users'));
        self::assertNull($connection->fetchOne('SELECT tread_mm FROM tyre_change_lines'), 'the depth is gone with the column');
    }
}
