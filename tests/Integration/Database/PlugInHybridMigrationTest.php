<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 9.2's data migration: a `hybrid` that has ever been charged becomes
 * a `phev`, archived or not; every other vehicle is left alone, and the
 * rollback turns every `phev` back into `hybrid`.
 */
final class PlugInHybridMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the split. */
    private const string BEFORE = '20261003100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testHybridsAreSortedByTheirOwnHistory(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $charged = $this->vehicle($app, 'Mitsubishi', 'Outlander', fuel: FuelType::Hybrid);
        $this->fillUp($app, $charged, '2026-06-01T08:00:00Z', '1000', '40', '60', grade: FuelGrade::E10_95);
        $this->fillUp($app, $charged, '2026-06-02T19:00:00Z', '1050', '10', '3', grade: FuelGrade::Home);
        $this->fillUp($app, $charged, '2026-06-05T19:00:00Z', '1120', '11', '3.3', grade: FuelGrade::Home);
        $this->fillUp($app, $charged, '2026-06-20T08:00:00Z', '1700', '30', '45', grade: FuelGrade::E10_95);

        $petrolOnly = $this->vehicle($app, 'Toyota', 'Corolla', fuel: FuelType::Hybrid);
        $this->fillUp($app, $petrolOnly, '2026-06-01T08:00:00Z', '5000', '35', '52.5');

        $neverFilled = $this->vehicle($app, 'Toyota', 'Yaris', fuel: FuelType::Hybrid);

        $archived = $this->vehicle($app, 'Volvo', 'XC60', fuel: FuelType::Hybrid);
        $this->fillUp($app, $archived, '2025-01-01T19:00:00Z', '9000', '9', '2.7', grade: FuelGrade::Home);
        $this->service($app, VehicleService::class)->archive($this->owner($app), $archived);

        $petrol = $this->vehicle($app, 'Volkswagen', 'Golf');
        $diesel = $this->vehicle($app, 'Skoda', 'Octavia', fuel: FuelType::Diesel);
        $ev = $this->vehicle($app, 'Kia', 'EV6', fuel: FuelType::Electric);
        $this->fillUp($app, $ev, '2026-06-01T19:00:00Z', '200', '50', '14', grade: FuelGrade::Home);

        $before = $this->figures($app, $charged);
        $updatedAt = $this->updatedAt($app);

        // As a 1.1.0 install upgrading from 1.0.0: everything is still `hybrid`.
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        Migrator::run('migrate');

        $sorted = [
            'charged' => [$charged, FuelType::Phev],
            'petrol only' => [$petrolOnly, FuelType::Hybrid],
            'never filled' => [$neverFilled, FuelType::Hybrid],
            'archived and charged' => [$archived, FuelType::Phev],
            'petrol' => [$petrol, FuelType::Petrol],
            'diesel' => [$diesel, FuelType::Diesel],
            'electric' => [$ev, FuelType::Electric],
        ];
        $this->assertFuelTypes($app, $sorted);
        self::assertSame($updatedAt, $this->updatedAt($app), 'the owner changed nothing');
        self::assertEquals($before, $this->figures($app, $charged), 'every figure is identical after the upgrade');

        Migrator::run('rollback');
        $this->assertFuelTypes($app, [
            'charged' => [$charged, FuelType::Hybrid],
            'archived and charged' => [$archived, FuelType::Hybrid],
            'petrol only' => [$petrolOnly, FuelType::Hybrid],
            'electric' => [$ev, FuelType::Electric],
            'diesel' => [$diesel, FuelType::Diesel],
        ]);

        Migrator::run('migrate');
        $this->assertFuelTypes($app, $sorted);
    }

    public function testAPlugInHybridChangedByHandIsKeptByTheRollbackAsAHybrid(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $phev = $this->vehicle($app, 'BMW', '330e', fuel: FuelType::Phev);

        Migrator::run('rollback');
        $this->assertFuelTypes($app, ['phev' => [$phev, FuelType::Hybrid]]);
        Migrator::run('migrate');
        $this->assertFuelTypes($app, ['never charged' => [$phev, FuelType::Hybrid]]);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, array{Vehicle, FuelType}> $expected
     */
    private function assertFuelTypes(App $app, array $expected): void
    {
        $vehicles = $this->service($app, VehicleRepository::class);
        foreach ($expected as $label => [$vehicle, $type]) {
            self::assertSame($type, $vehicles->find($vehicle->userId, $vehicle->id)?->data->fuelType, $label);
        }
    }

    /**
     * Economy, cost and grade figures for both kinds of energy.
     *
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function figures(App $app, Vehicle $vehicle): array
    {
        $fuel = $this->service($app, FuelService::class);
        $current = $this->service($app, VehicleRepository::class)->find($vehicle->userId, $vehicle->id);
        self::assertNotNull($current);
        $history = $fuel->history($current);

        return [
            'liquid' => $history->summary(EnergyKind::Liquid),
            'electric' => $history->summary(EnergyKind::Electric),
            'total' => $history->totalCost(),
            'grades' => $fuel->gradeBreakdowns($history),
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<mixed>
     */
    private function updatedAt(App $app): array
    {
        return $this->connection($app)->createQueryBuilder()
            ->select('updated_at')
            ->from('vehicles')
            ->orderBy('id')
            ->executeQuery()
            ->fetchFirstColumn();
    }
}
