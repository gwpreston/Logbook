<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 29.2's wider `vehicles.disposal`: it holds `returned_lender` and
 * `returned_lessor`, and rolling back turns both into `sold`, keeping the
 * sale, and deletes the finance reminders with their deliveries.
 */
final class DisposalMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before it (Phase 29.1's finance agreements). */
    private const string BEFORE = '20261025100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackTurnsReturnedIntoSoldAndDropsFinanceReminders(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2028-02-10T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $yaris = $this->vehicle($app, 'Toyota', 'Yaris');
        $kia = $this->vehicle($app, 'Kia', 'EV6');
        $db = $this->connection($app);
        $db->update(
            'vehicles',
            ['disposal' => 'returned_lender', 'sale_price' => '8000', 'sale_date' => '2028-01-31'],
            ['id' => $yaris->id],
        );
        $db->update('vehicles', ['disposal' => 'returned_lessor', 'sale_date' => '2028-01-10'], ['id' => $kia->id]);
        foreach (['finance', 'finance_end', 'manual'] as $i => $source) {
            $db->insert('reminders', [
                'vehicle_id' => $yaris->id, 'source' => $source, 'source_id' => $source === 'manual' ? null : 1,
                'title' => $source, 'due_on' => '2028-03-01', 'lead_time_days' => 0, 'status' => 'upcoming',
                'occurrence' => (string) $i, 'created_at' => '2028-02-10 10:00:00', 'updated_at' => '2028-02-10 10:00:00',
            ]);
        }
        $id = $db->fetchOne('SELECT id FROM reminders WHERE source = ?', ['finance']);
        self::assertTrue(is_int($id) || is_string($id), 'the finance reminder was added');
        $finance = (int) $id;
        $db->insert('reminder_deliveries', [
            'reminder_id' => $finance, 'user_id' => $owner->id, 'status' => 'due', 'channels' => '["email"]',
            'sent_at' => '2028-02-10 10:00:00', 'created_at' => '2028-02-10 10:00:00',
        ]);

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        self::assertSame('sold', $db->fetchOne('SELECT disposal FROM vehicles WHERE id = ?', [$yaris->id]));
        self::assertSame('sold', $db->fetchOne('SELECT disposal FROM vehicles WHERE id = ?', [$kia->id]));
        self::assertEquals(8000, $db->fetchOne('SELECT sale_price FROM vehicles WHERE id = ?', [$yaris->id]), 'the sale is kept');
        self::assertEquals(1, $db->fetchOne('SELECT COUNT(*) FROM reminders'), 'only the manual reminder is left');
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM reminder_deliveries WHERE reminder_id = ?', [$finance]));

        Migrator::run('migrate');
        $db->update('vehicles', ['disposal' => 'returned_lender'], ['id' => $yaris->id]);
        self::assertSame('returned_lender', $db->fetchOne('SELECT disposal FROM vehicles WHERE id = ?', [$yaris->id]));
    }
}
