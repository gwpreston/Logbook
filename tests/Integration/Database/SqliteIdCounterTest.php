<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * A SQLite migration that rebuilds a table keeps the table's id counter, so an
 * id deleted before an upgrade or rollback is never given to a later record
 * (Phase 53.16, K63). The other engines never rebuild, so only SQLite runs it.
 */
final class SqliteIdCounterTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before Phase 50.1's parts (rolling back rebuilds odometer_readings). */
    private const string BEFORE_PARTS = '20261108100000';

    /** Phase 50.1's parts migration (rolling back the next one rebuilds parts). */
    private const string PARTS = '20261109100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testADeletedReadingsIdIsNotReusedAcrossARollbackAndAnUpgrade(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $db = $this->connection($app);
        $this->onlyOnSqlite($db);
        $vehicle = $this->vehicle($app);
        $this->reading($app, $vehicle, '1000', '2026-01-01 10:00:00');
        $this->reading($app, $vehicle, '2000', '2026-02-01 10:00:00');
        $deleted = (int) $db->fetchOne('SELECT MAX(id) FROM odometer_readings');
        $db->executeStatement('DELETE FROM odometer_readings WHERE id = ?', [$deleted]);

        Migrator::run('rollback', ['--target' => self::BEFORE_PARTS]);
        self::assertSame($deleted, $this->counter($db, 'odometer_readings'), 'the rollback kept the counter');

        Migrator::run('migrate');
        self::assertSame($deleted, $this->counter($db, 'odometer_readings'), 'the upgrade kept it');

        $this->reading($app, $vehicle, '3000', '2026-03-01 10:00:00');
        self::assertGreaterThan($deleted, (int) $db->fetchOne('SELECT MAX(id) FROM odometer_readings'));
    }

    public function testADeletedPartsIdIsNotReusedWhenARollbackRebuildsTheTable(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $db = $this->connection($app);
        $this->onlyOnSqlite($db);
        $vehicle = $this->vehicle($app);
        $now = '2026-01-01 10:00:00';
        foreach (['Pads', 'Discs'] as $name) {
            $db->executeStatement(
                'INSERT INTO parts (vehicle_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [$vehicle->id, $name, $now, $now],
            );
        }
        $deleted = (int) $db->fetchOne('SELECT MAX(id) FROM parts');
        $db->executeStatement('DELETE FROM parts WHERE id = ?', [$deleted]);

        Migrator::run('rollback', ['--target' => self::PARTS]);
        self::assertSame($deleted, $this->counter($db, 'parts'));

        Migrator::run('migrate');
        $db->executeStatement(
            'INSERT INTO parts (vehicle_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$vehicle->id, 'Wipers', $now, $now],
        );
        self::assertGreaterThan($deleted, (int) $db->fetchOne('SELECT MAX(id) FROM parts'));
    }

    private function onlyOnSqlite(Connection $db): void
    {
        if (!$db->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Only SQLite rebuilds a table to change it.');
        }
    }

    private function counter(Connection $db, string $table): int
    {
        return (int) $db->fetchOne('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]);
    }
}
