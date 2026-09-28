<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Support\Database\Row;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 10's document odometer: rolling it back keeps the mileage (every
 * `document` reading becomes a `manual` one, link cleared) before the columns
 * go, and migrate → rollback → migrate is stable.
 */
final class DocumentOdometerMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the document odometer. */
    private const string BEFORE = '20261004100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testDocumentReadingsSurviveTheRollbackAsManualReadings(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, '40000.000', '2026-08-01T09:00:00Z');
        $this->service($app, ComplianceService::class)->create($golf, new ComplianceDocumentData(
            ComplianceType::Inspection,
            startOn: new \DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
            odometerKm: '48280.320',
        ), new DateTimeZone('Europe/London'));

        $this->connection($app)->insert('attachments', [
            'vehicle_id' => $golf->id,
            'owner_type' => 'expense',
            'owner_id' => 1,
            'filename' => 'a.pdf',
            'mime' => 'application/pdf',
            'size' => 1,
            'stored_path' => 'attachments/a.pdf',
            'uploaded_at' => '2026-09-01 10:00:00',
        ]);
        $readings = $this->service($app, OdometerReadingRepository::class);
        $sources = static fn (): array => array_map(
            static fn ($r): string => $r->source->value . ' ' . $r->readingKm,
            $readings->listForVehicle($golf->id),
        );
        self::assertSame(['manual 40000.000', 'document 48280.320'], $sources());

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $rows = $this->connection($app)->fetchAllAssociative(
            'SELECT source, reading_km, recorded_at FROM odometer_readings WHERE vehicle_id = ? ORDER BY recorded_at',
            [$golf->id],
        );
        self::assertCount(2, $rows, 'no mileage is lost');
        self::assertSame('manual', $rows[1]['source'], 'the document reading became a manual one');
        self::assertSame('48280.320', Row::decimal($rows[1], 'reading_km', 3));
        self::assertStringStartsWith('2026-09-01 11:00', Row::string($rows[1], 'recorded_at'));
        $owners = $this->connection($app)->fetchFirstColumn('SELECT owner_type FROM attachments');
        self::assertSame([], $owners, 'owner types 1.1.0 cannot read are unlinked');

        Migrator::run('migrate');
        self::assertSame(['manual 40000.000', 'manual 48280.320'], $sources(), 'stable after migrating again');
        $reading = $readings->listForVehicle($golf->id)[1];
        self::assertNull($reading->complianceDocumentId);
        self::assertNull($readings->findByEntry($golf->id, OdometerSource::Document, 1));
    }
}
