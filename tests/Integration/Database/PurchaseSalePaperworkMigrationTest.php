<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Repository\BackupRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 12's purchase and sale paperwork: no column changes, but the schema
 * version moves (so a 1.4.0 backup never restores into 1.3.x), and rolling
 * back removes only the rows of purchase and sale files.
 */
final class PurchaseSalePaperworkMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the paperwork. */
    private const string BEFORE = '20261007100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackRemovesOnlyPurchaseAndSaleRows(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $current = $this->service($app, BackupRepository::class)->schemaVersion();
        self::assertGreaterThan(self::BEFORE, $current, 'the schema version moves');

        foreach (['purchase', 'sale', 'expense'] as $i => $type) {
            $this->connection($app)->insert('attachments', [
                'vehicle_id' => $golf->id,
                'owner_type' => $type,
                'owner_id' => $type === 'expense' ? 1 : $golf->id,
                'filename' => $type . '.pdf',
                'mime' => 'application/pdf',
                'size' => 1,
                'stored_path' => 'attachments/' . $i . '.pdf',
                'uploaded_at' => '2026-09-01 10:00:00',
            ]);
        }

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        self::assertSame(self::BEFORE, $this->service($app, BackupRepository::class)->schemaVersion());
        $owners = $this->connection($app)->fetchFirstColumn('SELECT owner_type FROM attachments');
        self::assertSame(['expense'], $owners, 'owner types 1.3.x cannot read are unlinked');

        Migrator::run('migrate');
        $again = $this->service($app, BackupRepository::class)->schemaVersion();
        self::assertSame($current, $again, 'stable after migrating again');
        self::assertSame(['expense'], $this->connection($app)->fetchFirstColumn('SELECT owner_type FROM attachments'));
    }
}
