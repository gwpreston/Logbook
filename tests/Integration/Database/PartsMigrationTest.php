<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\PartData;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartService;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Access\EntryAccess;
use Logbook\Repository\ModificationRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;
use Slim\Psr7\UploadedFile;

/**
 * Rolling back Phase 50.1's parts migration keeps the money: an expense
 * that became a modification (*Make this a modification*) would otherwise
 * be lost with the table, so every modification comes back as an Other
 * expense with its files. Parts and fittings are dropped.
 */
final class PartsMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before it (Phase 41's MOT history). */
    private const string BEFORE = '20261108100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackTurnsEachModificationIntoAnExpenseWithItsFiles(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $zone = new DateTimeZone('UTC');
        $expense = $this->service($app, ExpenseService::class)->create(
            $golf,
            new ExpenseEntryData(new DateTimeImmutable('2026-03-01'), ExpenseCategory::Other, '450.000', 'Remap'),
            self::receipt(),
        );
        $this->service($app, ModificationService::class)->fromExpense($golf, $expense, new ModificationData(
            'Stage 1 remap',
            ModificationCategory::Other,
            new DateTimeImmutable('2026-03-01'),
            null,
            '450.000',
        ), $zone);
        $this->service($app, PartService::class)->create($golf, new PartData('Spare bulb'), $zone);
        $db = $this->connection($app);
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM expense_entries'), 'the expense became a modification');

        Migrator::run('rollback', ['--target' => self::BEFORE]);

        $rows = $db->fetchAllAssociative('SELECT id, vehicle_id, spent_on, category, amount, note FROM expense_entries');
        self::assertCount(1, $rows);
        self::assertEquals($golf->id, $rows[0]['vehicle_id']);
        self::assertIsString($rows[0]['spent_on']);
        self::assertSame('2026-03-01', substr($rows[0]['spent_on'], 0, 10));
        self::assertSame('other', $rows[0]['category']);
        self::assertIsNumeric($rows[0]['amount']);
        self::assertEqualsWithDelta(450.0, (float) $rows[0]['amount'], 0.0001);
        self::assertSame('Stage 1 remap', $rows[0]['note']);
        $files = $db->fetchAllAssociative('SELECT owner_type, owner_id FROM attachments');
        self::assertCount(1, $files, 'the receipt stays');
        self::assertSame('expense', $files[0]['owner_type']);
        self::assertEquals($rows[0]['id'], $files[0]['owner_id']);

        Migrator::run('migrate');
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM parts'), 'parts start again empty');
    }

    /**
     * A note *Make this a modification* kept whole in Notes (#431) comes back
     * whole, and a rollback that stopped part-way (MySQL and MariaDB commit at
     * each schema change) completes when run again: here a foreign key and a
     * whole link column are already gone.
     */
    public function testRollingBackKeepsALongConvertedNoteAndCanFinishAPartDoneRollback(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $note = str_repeat('Long text ', 49) . 'end';
        $expense = $this->service($app, ExpenseService::class)->create(
            $golf,
            new ExpenseEntryData(new DateTimeImmutable('2026-03-01'), ExpenseCategory::Other, '12.345', $note),
        );
        $this->service($app, ModificationService::class)->fromExpense($golf, $expense, new ModificationData(
            mb_substr($note, 0, 120),
            ModificationCategory::Other,
            new DateTimeImmutable('2026-03-01'),
            null,
            '12.345',
            notes: $note,
        ), new DateTimeZone('UTC'));
        $this->service($app, ModificationService::class)->create($golf, new ModificationData(
            'Tow bar',
            ModificationCategory::Other,
            new DateTimeImmutable('2026-04-01'),
            null,
            '99.000',
            notes: 'Fitted by the dealer',
        ), new DateTimeZone('UTC'));
        $db = $this->connection($app);
        // A rollback that stopped after its first schema change: one foreign key already gone
        // (SQLite rebuilds the table in one step, so it has no part-done state of this kind).
        if (!$db->getDatabasePlatform() instanceof SQLitePlatform) {
            $schema = $db->createSchemaManager();
            $schema->dropForeignKey('odometer_readings_mod_fk', 'odometer_readings');
            // ...and the first link column gone altogether, with its key and index.
            $schema->dropForeignKey('odometer_readings_part_removed_fk', 'odometer_readings');
            $schema->dropIndex('odometer_readings_part_removed_uniq', 'odometer_readings');
            $db->executeStatement('ALTER TABLE odometer_readings DROP COLUMN part_removed_id');
        }

        Migrator::run('rollback', ['--target' => self::BEFORE]);

        $notes = $db->fetchFirstColumn('SELECT note FROM expense_entries ORDER BY amount');
        self::assertSame([$note, 'Tow bar'], $notes, 'the whole converted note; a native one keeps its title');
        self::assertEquals(0, $db->fetchOne(
            "SELECT COUNT(*) FROM phinxlog WHERE version = '20261109100000'",
        ), 'the rollback finished');
    }

    /**
     * A restored expense belongs to the vehicle's owner, never the
     * modification's author: a member without costs who added it would
     * otherwise see the cost the owner typed as their own entry (#435).
     */
    public function testRollingBackGivesEachRestoredExpenseToTheVehiclesOwner(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->pinClock($app, '2026-10-14T10:00:00Z');
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-10-14'));
        $theirs = $this->browserFor($app, 'logger');
        $theirs->post($v . '/modifications/new', ['title' => 'Tow bar', 'category' => 'other', 'done_on' => '2026-05-10']);
        $modification = $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id])[0];
        self::assertSame($member->id, $modification->createdBy, 'the member added it');
        $owner->post($v . '/modifications/' . $modification->id . '/edit', [
            'title' => 'Tow bar', 'category' => 'other', 'done_on' => '2026-05-10', 'cost' => '7777',
        ]);

        Migrator::run('rollback', ['--target' => self::BEFORE]);

        $row = $this->connection($app)->fetchAssociative('SELECT created_by, amount FROM expense_entries');
        self::assertIsArray($row);
        self::assertEquals($golf->userId, $row['created_by']);
        self::assertIsNumeric($row['amount']);
        self::assertEqualsWithDelta(7777.0, (float) $row['amount'], 0.0001);
        self::assertIsNumeric($row['created_by']);
        self::assertFalse(
            $this->service($app, EntryAccess::class)->canSeeAmount($member, $golf, (int) $row['created_by']),
            'the member still can\'t see the cost',
        );
    }

    private static function receipt(): PendingUploads
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, "%PDF-1.4\n%receipt\n");
        $file = new UploadedFile($path, 'receipt.pdf', 'application/pdf', (int) filesize($path), UPLOAD_ERR_OK);

        return new PendingUploads([new PendingUpload($file, FileUpload::check($file, 1024 * 1024, UploadKind::Document))]);
    }

    /**
     * Rolling back Phase 50.2's migration removes the part reminders and the deliveries recorded for them, even on
     * SQLite, where Phinx runs with foreign keys off and the cascade never fires: a backup taken afterwards must
     * restore (merge review, upgrade-tester).
     */
    public function testRollingBackTheWarrantyReminderMigrationLeavesNoOrphanDeliveries(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $db = $this->connection($app);
        $db->insert('reminders', [
            'vehicle_id' => $golf->id, 'source' => 'part', 'source_id' => 1, 'occurrence' => '2028-03-01|',
            'title' => 'Warranty ends: Clutch', 'due_on' => '2028-03-01', 'lead_time_days' => 30, 'status' => 'due',
            'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
        ]);
        $id = (int) $db->lastInsertId();
        $db->insert('reminder_deliveries', [
            'reminder_id' => $id, 'user_id' => $owner->id, 'status' => 'due', 'created_at' => '2026-10-01 10:00:00',
        ]);

        Migrator::run('rollback', ['--target' => '20261109100000']);

        self::assertEquals(0, $db->fetchOne("SELECT COUNT(*) FROM reminders WHERE source = 'part'"));
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM reminder_deliveries'), 'no orphan delivery rows');
    }
}
