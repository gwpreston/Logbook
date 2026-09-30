<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Exception as DbalException;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 21.2's `vehicles.first_inspection_due_on`: existing vehicles get
 * null, and rolling back removes the column, the `first_inspection`
 * reminders with their deliveries, and the prompt setting, keeping
 * everything else.
 */
final class FirstInspectionMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the first MOT date (Phase 19's users and sharing). */
    private const string BEFORE = '20261012100000';
    private const string PROMPTED = 'vehicles.first_inspection_prompted';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackRemovesTheRemindersTheSettingAndTheColumn(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-30T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $vehicles = $this->service($app, VehicleService::class);
        $kia = $vehicles->create($owner, new VehicleData(
            VehicleType::Car,
            'Kia',
            'EV6',
            FuelType::Electric,
            firstRegisteredOn: LocalTime::parseDate('2023-10-20'),
            firstInspectionDueOn: LocalTime::parseDate('2026-10-20'),
        ));
        $golf = $this->vehicle($app);
        $this->document($app, $golf, ComplianceType::Insurance, '2026-01-01', '2026-10-15', '0');
        $this->service($app, ReminderSync::class)->sync($owner);
        $this->service($app, FirstInspectionPrompt::class)->settle($golf);
        $db = $this->connection($app);
        $id = $db->fetchOne('SELECT id FROM reminders WHERE source = ?', ['first_inspection']);
        self::assertTrue(is_int($id) || is_string($id), 'the first MOT reminder was raised');
        $reminder = (int) $id;
        $db->insert('reminder_deliveries', [
            'reminder_id' => $reminder, 'user_id' => $owner->id, 'status' => 'due', 'channels' => '["email"]',
            'sent_at' => '2026-09-30 10:00:00', 'created_at' => '2026-09-30 10:00:00',
        ]);

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        try {
            $db->executeQuery('SELECT first_inspection_due_on FROM vehicles WHERE 1 = 0');
            self::fail('rollback must drop the column');
        } catch (DbalException) {
            // Gone.
        }
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM reminders WHERE source = ?', ['first_inspection']));
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM reminder_deliveries WHERE reminder_id = ?', [$reminder]));
        self::assertEquals(1, $db->fetchOne('SELECT COUNT(*) FROM reminders WHERE source = ?', ['compliance']), 'the rest stays');
        self::assertEquals(2, $db->fetchOne('SELECT COUNT(*) FROM vehicles'));
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM settings WHERE name = ?', [self::PROMPTED]));

        Migrator::run('migrate');
        self::assertNull(
            $db->fetchOne('SELECT first_inspection_due_on FROM vehicles WHERE id = ?', [$kia->id]),
            'upgrading adds the column empty',
        );
        self::assertNull($this->service($app, SettingRepository::class)->find(self::PROMPTED, SettingScope::User, $owner->id));
    }
}
