<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Repository\AttachmentRepository;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 14.1's `vehicle_valuations`: a new table that changes no existing
 * figure; rolling back drops it and the `valuation` attachment rows, and
 * keeps everything else.
 */
final class ValuationsMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the valuations table. */
    private const string BEFORE = '20261009100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackDropsTheValuationsAndChangesNoFigure(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '1000', '40', '60');
        $this->expense($app, $golf, '2026-09-10', '12.50');
        $reports = $this->service($app, ReportService::class);
        $filter = new ReportFilter(ReportPeriod::preset(ReportRange::AllTime, new DateTimeImmutable('2026-09-27')), $golf->id);
        $before = $reports->compare($owner, [$golf], [$filter])[0]->currencies[0]->total;

        $this->service($app, ValuationService::class)->create(
            $golf,
            new VehicleValuationData(new DateTimeImmutable('2026-09-15'), '9800.000', 'Dealer'),
        );
        $this->connection($app)->insert('attachments', [
            'vehicle_id' => $golf->id,
            'owner_type' => AttachmentOwner::Valuation->value,
            'owner_id' => 1,
            'filename' => 'quote.png',
            'mime' => 'image/png',
            'size' => 1,
            'stored_path' => 'attachments/quote.png',
            'uploaded_at' => '2026-09-15 10:00:00',
        ]);
        $after = $reports->compare($owner, [$golf], [$filter])[0]->currencies[0]->total;
        self::assertTrue($before->equals($after), 'a valuation is not a cost');

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $schema = $this->connection($this->createApp())->createSchemaManager();
        self::assertFalse($schema->tablesExist(['vehicle_valuations']), 'rollback must drop vehicle_valuations');
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM attachments'), 'and its attachment rows');
        self::assertEquals(1, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM expense_entries'), 'the rest stays');

        Migrator::run('migrate');
        self::assertTrue($schema->tablesExist(['vehicle_valuations']));
        self::assertSame([], $this->service($app, AttachmentRepository::class)->listForVehicle($golf->id));
        $again = $reports->compare($owner, [$golf], [$filter])[0]->currencies[0]->total;
        self::assertTrue($before->equals($again), 'every figure is as it was');
    }
}
