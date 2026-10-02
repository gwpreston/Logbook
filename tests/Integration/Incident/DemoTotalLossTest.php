<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Incident;

use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * The sample data (db/seeds/DemoDataSeeder.php, Phase 27.2): the Fiesta is
 * archived as written off by its settled Cat S collision, the settlement
 * its sale price and counted once; the Golf's scrape has a repair
 * estimate that changes no cost.
 */
final class DemoTotalLossTest extends AppTestCase
{
    public function testTheFiestaIsWrittenOffAndTheGolfHasAnEstimate(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $vehicles = $this->service($app, VehicleService::class)->listFleet($demo, true);
        $byPlate = [];
        foreach ($vehicles as $vehicle) {
            $byPlate[(string) $vehicle->data->registration] = $vehicle;
        }
        $incidents = $this->service($app, IncidentRepository::class);

        $fiesta = $byPlate['WR14 FNE'];
        self::assertTrue($fiesta->isArchived());
        self::assertSame(Disposal::WrittenOff, $fiesta->disposal);
        self::assertNotNull($fiesta->disposalIncidentId);
        $loss = $incidents->find($fiesta->id, $fiesta->disposalIncidentId);
        self::assertNotNull($loss);
        self::assertSame(WriteOffCategory::CatS, $loss->data->writeOff);
        self::assertSame($fiesta->data->salePrice, $loss->data->claim->payout, 'the settlement is the sale price');

        $today = LocalTime::parseDate('2026-09-29');
        self::assertNotNull($today);
        $report = $this->service($app, OwnershipService::class)
            ->report($demo, ReportFilter::fromQuery(['vehicle' => (string) $fiesta->id], $today), $today);
        $cost = $report->rows()[0];
        self::assertTrue($cost->settlementIsSale);
        self::assertNull($cost->payouts, 'the settlement is not also a payout');

        $golf = $byPlate['LB19 KTR'];
        $estimated = array_values(array_filter(
            $incidents->listForVehicle($golf->id),
            static fn ($incident): bool => $incident->data->claim->repairEstimate !== null,
        ));
        self::assertCount(1, $estimated);
        self::assertSame('655.000', $estimated[0]->data->claim->repairEstimate);
    }
}
