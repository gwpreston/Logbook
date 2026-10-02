<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Finance;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\User\User;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * The sample data's finance (db/seeds/DemoDataSeeder.php, Phase 29.2): the
 * Kia's lease replaces its rental expenses, the Corolla's PCP heads about
 * 1,200 mi over with equity from a recent valuation, and the written-off
 * Fiesta's HP was settled early with the lender's quote.
 */
final class DemoFinanceTest extends AppTestCase
{
    public function testTheDemoGarageHasALeaseAPcpAndASettledHp(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $byPlate = [];
        foreach ($this->service($app, VehicleService::class)->listFleet($demo, true) as $vehicle) {
            $byPlate[(string) $vehicle->data->registration] = $vehicle;
        }
        $finance = $this->service($app, FinanceService::class);

        $kia = $finance->activeView($demo, $byPlate['EV23 KIA']);
        self::assertNotNull($kia);
        self::assertSame(AgreementType::Lease, $kia->agreement->type());
        $rentals = array_filter(
            $this->service($app, ExpenseEntryRepository::class)->listForVehicle($byPlate['EV23 KIA']->id),
            static fn ($entry): bool => $entry->data->category === ExpenseCategory::Finance,
        );
        self::assertSame([], $rentals, 'the rentals count once, from the agreement');

        $corolla = $finance->activeView($demo, $byPlate['LK22 VXN']);
        self::assertNotNull($corolla);
        self::assertSame(AgreementType::Pcp, $corolla->agreement->type());
        $mileage = $corolla->mileage;
        self::assertNotNull($mileage);
        self::assertNotNull($mileage->excessKm);
        $over = $mileage->unit->fromKm((float) $mileage->excessKm);
        self::assertSame(DistanceUnit::Mile, $mileage->unit);
        self::assertEqualsWithDelta(1200, $over, 150, 'heading about 1,200 mi over');
        self::assertGreaterThan(2.0, (float) $mileage->excessPercent(), 'in Needs attention');
        self::assertNotNull($corolla->figures->equity, 'a recent valuation gives equity');
        self::assertSame([], $corolla->checks, 'its figures add up');

        $agreements = $finance->forVehicle($demo, $byPlate['WR14 FNE']);
        self::assertCount(1, $agreements);
        self::assertSame(AgreementStatus::Settled, $agreements[0]->status);
        $view = $finance->view($demo, $byPlate['WR14 FNE'], $agreements[0]);
        self::assertCount(1, $view->quotes);
        self::assertTrue($view->figures->costOfCredit?->exact);
    }
}
