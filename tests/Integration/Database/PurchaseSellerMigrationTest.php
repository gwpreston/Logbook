<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeZone;
use Doctrine\DBAL\Exception as DbalException;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 33.3's `vehicles.purchase_seller`, `purchase` readings and
 * `breakdown` incidents: rolling back keeps the mileage as a manual reading,
 * turns a breakdown into `other` and drops the column; migrating again adds
 * it back empty.
 */
final class PurchaseSellerMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before it (Phase 33.1's account email and avatar). */
    private const string BEFORE = '20261030100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackKeepsTheMileageAndTheIncidentAndDropsTheColumn(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-05T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            purchaseDate: LocalTime::parseDate('2021-05-01'),
            purchaseSeller: 'Halden Motors',
        ), purchaseKm: '48280.32');
        $this->reading($app, $golf, '50000', '2022-01-01T12:00:00Z');
        $this->service($app, IncidentService::class)->create($golf, new IncidentData(
            LocalTime::parseDate('2026-09-01') ?? throw new \LogicException('date'),
            IncidentType::Breakdown,
        ), null, new DateTimeZone('Europe/London'));
        $db = $this->connection($app);
        self::assertSame('Halden Motors', $db->fetchOne('SELECT purchase_seller FROM vehicles WHERE id = ?', [$golf->id]));
        self::assertSame('breakdown', $db->fetchOne('SELECT type FROM incidents'));

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        try {
            $db->executeQuery('SELECT purchase_seller FROM vehicles WHERE 1 = 0');
            self::fail('rollback must drop the column');
        } catch (DbalException) {
            // Gone.
        }
        self::assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM odometer_readings WHERE source = ?', ['purchase']));
        $manual = $db->fetchOne('SELECT COUNT(*) FROM odometer_readings WHERE source = ?', ['manual']);
        self::assertEquals(2, $manual, 'no mileage is lost');
        self::assertEquals(1, $db->fetchOne('SELECT COUNT(*) FROM odometer_readings WHERE reading_km = ?', ['48280.320']));
        self::assertSame('other', $db->fetchOne('SELECT type FROM incidents'));
        self::assertEquals(1, $db->fetchOne('SELECT COUNT(*) FROM vehicles'), 'the vehicle stays');

        Migrator::run('migrate');
        self::assertNull($db->fetchOne('SELECT purchase_seller FROM vehicles WHERE id = ?', [$golf->id]));
    }
}
