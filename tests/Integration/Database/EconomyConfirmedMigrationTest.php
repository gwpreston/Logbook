<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Exception as DbalException;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Fuel\EconomySummary;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Service\Fuel\FuelService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 13's `fuel_entries.economy_confirmed`: null for every existing
 * fill-up, so no figure changes with the upgrade; rolling back drops only
 * the confirmations.
 */
final class EconomyConfirmedMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before the economy confirmation. */
    private const string BEFORE = '20261008100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackDropsOnlyTheConfirmationsAndNoFigureChanges(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '1000', '40', '60');
        $second = $this->fillUp($app, $golf, '2026-09-08T08:00:00Z', '1500', '35', '52.5');
        $this->fillUp($app, $golf, '2026-09-15T08:00:00Z', '2100', '60', '90');
        $fuel = $this->service($app, FuelService::class);
        $before = self::figures($fuel->history($golf));
        $this->service($app, FuelEntryRepository::class)->setEconomyConfirmed($golf->id, $second->id, '7.000000');
        self::assertSame($before, self::figures($fuel->history($golf)), 'a confirmation changes no figure');

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $query = $this->connection($this->createApp())->createQueryBuilder()->select('economy_confirmed')->from('fuel_entries');
        try {
            $query->executeQuery();
            self::fail('rollback must drop fuel_entries.economy_confirmed');
        } catch (DbalException) {
        }
        self::assertEquals(3, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM fuel_entries'), 'the fill-ups stay');

        Migrator::run('migrate');
        $entries = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertSame([null, null, null], array_map(static fn ($e): ?string => $e->economyConfirmed, $entries));
        self::assertSame($before, self::figures($fuel->history($golf)), 'every figure is as it was');
    }

    /**
     * Every figure a summary shows.
     *
     * @return array<string, list<int|string|null>>
     */
    private static function figures(FuelHistory $history): array
    {
        return array_map(static fn (EconomySummary $s): array => [
            $s->fills,
            $s->totalVolume,
            $s->totalCost,
            $s->measuredDistanceKm,
            $s->measuredVolume,
            $s->measuredCost,
            $s->segments,
            $s->averagePricePerUnit(),
            $s->costPerKm(),
        ], $history->summaries);
    }
}
