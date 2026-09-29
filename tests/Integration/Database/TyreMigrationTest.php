<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeZone;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Support\Database\Row;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 11.1's tyre tables: rolling them back keeps the mileage (every
 * `tyre` reading becomes a `manual` one, link cleared) before the tables go,
 * and migrate → rollback → migrate is stable.
 */
final class TyreMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the tyre tables. */
    private const string BEFORE = '20261005100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testTyreReadingsSurviveTheRollbackAsManualReadings(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, '40000.000', '2026-08-01T09:00:00Z');
        $done = LocalTime::parseDate('2026-09-01');
        assert($done !== null);
        $this->service($app, TyreChangeService::class)->existing(
            $golf,
            new TyreChangeData($done, '48280.320'),
            [new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'))],
            new DateTimeZone('Europe/London'),
            'en_GB',
        );

        $readings = $this->service($app, OdometerReadingRepository::class);
        $sources = static fn (): array => array_map(
            static fn ($r): string => $r->source->value . ' ' . $r->readingKm,
            $readings->listForVehicle($golf->id),
        );
        self::assertSame(['manual 40000.000', 'tyre 48280.320'], $sources());

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $rows = $this->connection($app)->fetchAllAssociative(
            'SELECT source, reading_km, recorded_at FROM odometer_readings WHERE vehicle_id = ? ORDER BY recorded_at',
            [$golf->id],
        );
        self::assertCount(2, $rows, 'no mileage is lost');
        self::assertSame('manual', $rows[1]['source'], 'the tyre reading became a manual one');
        self::assertSame('48280.320', Row::decimal($rows[1], 'reading_km', 3));
        self::assertStringStartsWith('2026-09-01 11:00', Row::string($rows[1], 'recorded_at'));
        $schema = $this->connection($app)->createSchemaManager();
        self::assertFalse($schema->tablesExist(['tyres']));

        Migrator::run('migrate');
        self::assertSame(['manual 40000.000', 'manual 48280.320'], $sources(), 'stable after migrating again');
        self::assertTrue($schema->tablesExist(['tyre_sets', 'tyres', 'tyre_changes', 'tyre_change_lines']));
    }
}
