<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;
use RuntimeException;

/**
 * Phase 19's users and sharing migration: the one existing user becomes an
 * admin, what 1.x already notified is recorded as delivered to the owner,
 * and rolling back works with one user and is refused with two.
 */
final class UsersAndSharingMigrationTest extends AppTestCase
{
    /** The migration before users and sharing (Phase 18.2's API keys). */
    private const string BEFORE = '20261011100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testUpgradingMakesTheUserAnAdminAndRecordsWhatWasSent(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $db = $this->connection($app);

        $db->insert('users', [
            'username' => 'owner', 'password_hash' => 'x', 'display_name' => 'Pat', 'locale' => 'en_GB',
            'timezone' => 'Europe/London', 'distance_unit' => 'mi', 'volume_unit' => 'l', 'consumption_unit' => 'mpg_uk',
            'depth_unit' => 'mm', 'currency' => 'GBP', 'theme' => 'system', 'accent' => 'blue',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $user = (int) $db->lastInsertId();
        $db->insert('vehicles', [
            'user_id' => $user, 'type' => 'car', 'make' => 'VW', 'model' => 'Golf', 'fuel_type' => 'petrol',
            'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $vehicle = (int) $db->lastInsertId();
        $reminder = static fn (string $title, string $status, ?string $notified): array => [
            'vehicle_id' => $vehicle, 'source' => 'manual', 'title' => $title, 'due_on' => '2026-09-01',
            'lead_time_days' => 7, 'status' => $status, 'notified_status' => $notified,
            'channels_notified' => $notified === null ? null : '["email"]',
            'last_notified_at' => $notified === null ? null : '2026-09-01 07:00:00',
            'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-09-01 07:00:00',
        ];
        $db->insert('reminders', $reminder('MOT', 'overdue', 'overdue'));
        $sent = (int) $db->lastInsertId();
        $db->insert('reminders', $reminder('Tax', 'overdue', 'due'));
        $dueOnly = (int) $db->lastInsertId();
        $db->insert('reminders', $reminder('Wash', 'upcoming', null));
        $db->insert('odometer_readings', [
            'vehicle_id' => $vehicle, 'reading_km' => '1000.000', 'recorded_at' => '2026-09-01 07:00:00', 'source' => 'manual',
            'created_at' => '2026-09-01 07:00:00', 'updated_at' => '2026-09-01 07:00:00',
        ]);

        Migrator::run('migrate');

        $admin = $db->fetchOne('SELECT is_admin FROM users WHERE id = ?', [$user]);
        self::assertTrue((bool) $admin, 'the existing user is an admin');
        self::assertNull($db->fetchOne('SELECT disabled_at FROM users WHERE id = ?', [$user]));
        $rows = $db->fetchAllAssociative(
            'SELECT reminder_id, user_id, status, sent_at FROM reminder_deliveries ORDER BY reminder_id',
        );
        self::assertCount(2, $rows, 'one delivery per reminder already notified');
        self::assertEquals([$sent, $user, 'overdue'], [$rows[0]['reminder_id'], $rows[0]['user_id'], $rows[0]['status']]);
        self::assertEquals([$dueOnly, $user, 'due'], [$rows[1]['reminder_id'], $rows[1]['user_id'], $rows[1]['status']]);
        self::assertNotNull($rows[0]['sent_at']);
        self::assertEquals($user, $db->fetchOne('SELECT created_by FROM odometer_readings'), 'what was there is the owner\'s');
    }

    public function testRollingBackWorksWithOneUserAndIsRefusedWithTwo(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $this->createMember($app, 'partner');

        try {
            Migrator::run('rollback');
            self::fail('rolling back with two users must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('bin/export-user.php', $e->getMessage());
        }
        self::assertEquals(2, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM users'), 'nothing changed');
        $schema = $this->connection($app)->createSchemaManager();
        self::assertTrue($schema->tablesExist(['vehicle_shares']), 'the schema is intact');

        $this->connection($app)->delete('users', ['username' => 'partner']);
        Migrator::run('rollback');
        self::assertFalse($schema->tablesExist(['vehicle_shares']));
        self::assertEquals(1, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM users'), 'the user stays');
    }
}
