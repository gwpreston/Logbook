<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Tyre;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition as P;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\SetChoice;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreCost;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\TyresBlockTypeChange;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Tyre changes through the service against a real database (spec.md
 * §7.17): the replay, one reading per event, the linked service record that
 * carries the cost, and refusals that leave nothing behind.
 */
final class TyreChangeServiceTest extends AppTestCase
{
    use CostFixtures;

    private const string ZONE = 'Europe/London';

    /** @var App<ContainerInterface> */
    private App $app;
    private Vehicle $car;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->car = $this->vehicle($this->app);
    }

    private function changes(): TyreChangeService
    {
        return $this->service($this->app, TyreChangeService::class);
    }

    private function tyres(): TyreService
    {
        return $this->service($this->app, TyreService::class);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }

    /**
     * @param list<P> $positions
     * @return list<NewTyre>
     */
    private static function newTyres(array $positions, string $brand = 'Michelin', string $model = 'Primacy 4'): array
    {
        $data = new TyreData($brand, $model, '205/55 R16 91V');

        return array_map(static fn (P $p): NewTyre => new NewTyre($p, $data), $positions);
    }

    private function start(string $date = '2025-10-03', string $km = '20000.000'): TyreChange
    {
        return $this->changes()->existing(
            $this->car,
            new TyreChangeData(self::day($date), $km),
            self::newTyres([P::FrontLeft, P::FrontRight, P::RearLeft, P::RearRight], 'Goodyear', 'EfficientGrip'),
            self::zone(),
            'en_GB',
        );
    }

    /**
     * @return list<string> "source km" per reading, oldest first
     */
    private function readings(): array
    {
        return array_map(
            static fn ($r): string => $r->source->value . ' ' . $r->readingKm,
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($this->car->id),
        );
    }

    /**
     * @return array<string, string> position → brand of the fitted tyre
     */
    private function fitted(): array
    {
        $fitted = [];
        foreach ($this->tyres()->tyres($this->car) as $tyre) {
            if ($tyre->status === TyreStatus::Fitted && $tyre->position !== null) {
                $fitted[$tyre->position->value] = (string) $tyre->data->brand;
            }
        }
        ksort($fitted);

        return $fitted;
    }

    private function rows(string $table): int
    {
        $count = $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM ' . $table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    public function testExistingTyresAreFittedAndTheirReadingJoinsTheSeries(): void
    {
        $change = $this->start();

        self::assertSame(['fl' => 'Goodyear', 'fr' => 'Goodyear', 'rl' => 'Goodyear', 'rr' => 'Goodyear'], $this->fitted());
        self::assertSame(['tyre 20000.000'], $this->readings());
        $reading = $this->service($this->app, OdometerReadingRepository::class)
            ->findByEntry($this->car->id, OdometerSource::Tyre, $change->id);
        self::assertNotNull($reading);
        self::assertSame('2025-10-03 11:00', $reading->recordedAt->format('Y-m-d H:i'), 'local noon (BST)');
    }

    public function testFitWithACostWritesExactlyOneServiceRecordAndOneReading(): void
    {
        $this->start();
        $fit = $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000'),
            self::newTyres([P::FrontLeft, P::FrontRight]),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            new TyreCost('240.000', 'Kwik Fit'),
            self::zone(),
            'en_GB',
        );

        self::assertNotNull($fit->data->maintenanceEntryId);
        $record = $this->service($this->app, MaintenanceService::class)->get($this->car, $fit->data->maintenanceEntryId);
        self::assertSame('2 × Michelin Primacy 4, front', $record->data->title);
        self::assertSame(MaintenanceCategory::Tyres, $record->data->category);
        self::assertSame('240.000', $record->data->cost);
        self::assertSame('Kwik Fit', $record->data->vendor);
        self::assertSame('2026-04-10', $record->data->performedOn->format('Y-m-d'));
        self::assertSame(1, $this->rows('maintenance_entries'));
        self::assertSame(['tyre 20000.000', 'maintenance 32000.000'], $this->readings(), 'one reading, owned by the record');
        self::assertSame(['fl' => 'Michelin', 'fr' => 'Michelin', 'rl' => 'Goodyear', 'rr' => 'Goodyear'], $this->fitted());

        // The retired fronts: 12,000 km each, £120 each → £0.01/km.
        $retired = $this->tyres()->overview($this->car, $this->owner)->retired;
        self::assertCount(2, $retired);
        self::assertSame('12000.000', $retired[0]->distance->km);
        self::assertSame(TyreRetireReason::Worn, $retired[0]->tyre->retiredReason);
        self::assertNull($retired[0]->costPerKm, 'fitted as existing tyres: no fitting cost to split');
    }

    public function testCostPerDistanceSplitsTheFittingRecordAndShowsNoneWhileFitted(): void
    {
        $this->start();
        $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-01-10'), '25000.000'),
            self::newTyres([P::FrontLeft, P::FrontRight]),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            new TyreCost('240.000'),
            self::zone(),
            'en_GB',
        );
        $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-08-10'), '49000.000'),
            self::newTyres([P::FrontLeft, P::FrontRight], 'Continental', 'EcoContact 6'),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            null,
            self::zone(),
            'en_GB',
        );

        $overview = $this->tyres()->overview($this->car, $this->owner);
        $michelins = array_values(array_filter($overview->retired, static fn ($v): bool => $v->tyre->data->brand === 'Michelin'));
        self::assertCount(2, $michelins);
        self::assertSame('24000.000', $michelins[0]->distance->km);
        self::assertSame('0.005000', $michelins[0]->costPerKm, '£120 over 24,000 km');
        self::assertNull($overview->at(P::FrontLeft)?->costPerKm, 'a fitted tyre shows none');
        self::assertNotNull($overview->at(P::RearLeft)?->since, 'distance of existing tyres counts "since"');
        self::assertSame('2025-10-03', $overview->at(P::RearLeft)->since->format('Y-m-d'));
        self::assertNull($overview->at(P::FrontLeft)?->since);
    }

    public function testLinkingAnExistingRecordWritesNoSecondReadingAndTakesItsDateAndOdometer(): void
    {
        $this->start();
        $record = $this->maintenance($this->app, $this->car, '2026-04-08', 'Two new tyres', '230.000', '31990.000');

        $fit = $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000', $record->id),
            self::newTyres([P::FrontLeft, P::FrontRight]),
            ['fl' => null, 'fr' => null],
            null,
            self::zone(),
            'en_GB',
        );

        self::assertSame($record->id, $fit->data->maintenanceEntryId);
        self::assertSame('2026-04-08', $fit->data->doneOn->format('Y-m-d'), 'the record\'s date');
        self::assertSame('31990.000', $fit->data->odometerKm, 'and odometer');
        self::assertSame(['tyre 20000.000', 'maintenance 31990.000'], $this->readings());
        self::assertSame(1, $this->rows('maintenance_entries'));
    }

    public function testLinkingARecordWithoutAnOdometerGivesItTheChanges(): void
    {
        $this->start();
        $record = $this->maintenance($this->app, $this->car, '2026-04-10', 'Two new tyres', '230.000');

        $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000', $record->id),
            self::newTyres([P::RearLeft, P::RearRight]),
            ['rl' => null, 'rr' => null],
            null,
            self::zone(),
            'en_GB',
        );

        $record = $this->service($this->app, MaintenanceService::class)->get($this->car, $record->id);
        self::assertSame('32000.000', $record->data->odometerKm);
        self::assertSame(['tyre 20000.000', 'maintenance 32000.000'], $this->readings(), 'still one reading');
    }

    public function testEditingTheRecordMovesTheChangeAndDeletingItLeavesTheChangeItsOwnReading(): void
    {
        $this->start();
        $fit = $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000'),
            self::newTyres([P::FrontLeft, P::FrontRight]),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            new TyreCost('240.000'),
            self::zone(),
            'en_GB',
        );
        $maintenance = $this->service($this->app, MaintenanceService::class);
        $record = $maintenance->get($this->car, (int) $fit->data->maintenanceEntryId);

        $maintenance->update($this->car, $record, new MaintenanceEntryData(
            self::day('2026-04-11'),
            MaintenanceCategory::Tyres,
            $record->data->title,
            '240.000',
            '32100.000',
        ), self::zone());
        $moved = $this->changes()->get($this->car, $fit->id);
        self::assertSame('2026-04-11', $moved->data->doneOn->format('Y-m-d'));
        self::assertSame('32100.000', $moved->data->odometerKm);
        self::assertSame(['tyre 20000.000', 'maintenance 32100.000'], $this->readings());

        $maintenance->delete($this->car, $maintenance->get($this->car, $record->id), self::zone());
        $kept = $this->changes()->get($this->car, $fit->id);
        self::assertNull($kept->data->maintenanceEntryId);
        self::assertSame(['tyre 20000.000', 'tyre 32100.000'], $this->readings(), 'the change now owns its reading');
    }

    public function testEditingTheRecordIsRefusedWhenItWouldBreakTheSequence(): void
    {
        $this->start('2025-10-03');
        $fit = $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000'),
            self::newTyres([P::FrontLeft, P::FrontRight]),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            new TyreCost('240.000'),
            self::zone(),
            'en_GB',
        );
        $maintenance = $this->service($this->app, MaintenanceService::class);
        $record = $maintenance->get($this->car, (int) $fit->data->maintenanceEntryId);

        try {
            $maintenance->update($this->car, $record, new MaintenanceEntryData(
                self::day('2025-09-01'),
                MaintenanceCategory::Tyres,
                $record->data->title,
                '240.000',
                '19000.000',
            ), self::zone());
            self::fail('moving the fitting before the tyres it replaced were on must be refused');
        } catch (TyreChangeRefused $refused) {
            self::assertSame('tyre.error.sequence.not_fitted', $refused->key);
        }
        $kept = $maintenance->get($this->car, $record->id);
        self::assertSame('2026-04-10', $kept->data->performedOn->format('Y-m-d'), 'unchanged');
        self::assertSame(['tyre 20000.000', 'maintenance 32000.000'], $this->readings());
    }

    public function testAFailureAtTheLastStepLeavesNoChangeLinesRecordOrReadingBehind(): void
    {
        $this->start('2025-10-03');
        $before = [$this->rows('tyre_changes'), $this->rows('tyre_change_lines'), $this->rows('tyres')];

        // Passes the checks against today's tyres, but dated before they were
        // fitted, so the replay (run after the record and the reading are
        // written) fails.
        try {
            $this->changes()->fit(
                $this->car,
                new TyreChangeData(self::day('2025-01-01'), '15000.000'),
                self::newTyres([P::FrontLeft, P::FrontRight]),
                ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
                new TyreCost('240.000'),
                self::zone(),
                'en_GB',
            );
            self::fail('expected a refusal');
        } catch (TyreChangeRefused $refused) {
            self::assertSame('tyre.error.sequence.not_fitted', $refused->key);
            self::assertSame('Goodyear EfficientGrip', $refused->params['tyre']);
        }

        self::assertSame($before, [$this->rows('tyre_changes'), $this->rows('tyre_change_lines'), $this->rows('tyres')]);
        self::assertSame(0, $this->rows('maintenance_entries'));
        self::assertSame(['tyre 20000.000'], $this->readings());
    }

    public function testSwapSetOffAndBack(): void
    {
        $this->start();
        $winters = $this->changes()->existing(
            $this->car,
            new TyreChangeData(self::day('2025-10-03'), '20000.000'),
            self::newTyres([P::Spare], 'Spare', 'Tyre'),
            self::zone(),
            'en_GB',
        );
        self::assertNotEmpty($winters->lines);

        $swap = $this->changes()->swap(
            $this->car,
            new TyreChangeData(self::day('2025-11-05'), '21500.000'),
            new SetChoice(newSet: new TyreSetData('Summer wheels', 'Garage loft')),
            [],
            null,
            self::zone(),
            'en_GB',
        );
        self::assertCount(4, $swap->lines, 'every road tyre off; the spare is left alone');
        self::assertSame(['spare' => 'Spare'], $this->fitted());
        $sets = $this->tyres()->sets($this->car);
        self::assertCount(1, $sets);

        $stored = array_keys($this->tyres()->lastPositions($this->car));
        $back = [];
        foreach ($this->tyres()->lastPositions($this->car) as $id => $position) {
            self::assertNotNull($position);
            $back[$id] = $position;
        }
        self::assertCount(4, $stored);
        $this->changes()->swap(
            $this->car,
            new TyreChangeData(self::day('2026-03-20'), '26000.000'),
            new SetChoice(),
            $back,
            null,
            self::zone(),
            'en_GB',
        );
        self::assertSame(
            ['fl' => 'Goodyear', 'fr' => 'Goodyear', 'rl' => 'Goodyear', 'rr' => 'Goodyear', 'spare' => 'Spare'],
            $this->fitted(),
        );
        $overview = $this->tyres()->overview($this->car, $this->owner);
        self::assertSame('1500.000', $overview->at(P::FrontLeft)?->distance->km, 'storage time is not counted');
        self::assertSame('0.000', $overview->at(P::Spare)?->distance->km, 'spare time is not counted');
    }

    public function testRotateMustBeAPermutation(): void
    {
        $this->start();
        /** @var array<string, int> $tyres */
        $tyres = [];
        foreach ($this->tyres()->tyres($this->car) as $tyre) {
            $tyres[$tyre->position->value ?? ''] = $tyre->id;
        }
        self::assertArrayHasKey('fl', $tyres);

        try {
            $this->changes()->rotate($this->car, new TyreChangeData(self::day('2026-01-01'), '25000.000'), [
                $tyres['fl'] => P::RearLeft,
            ], self::zone(), 'en_GB');
            self::fail('two tyres at the rear left');
        } catch (TyreChangeRefused $refused) {
            self::assertSame('tyre.error.not_permutation', $refused->key);
        }

        $rotation = $this->changes()->rotate($this->car, new TyreChangeData(self::day('2026-01-01'), '25000.000'), [
            $tyres['fl'] => P::RearLeft,
            $tyres['rl'] => P::FrontLeft,
            $tyres['fr'] => P::RearRight,
            $tyres['rr'] => P::FrontRight,
        ], self::zone(), 'en_GB');
        self::assertCount(4, $rotation->lines);
    }

    public function testFittingToAnOccupiedPositionNeedsAChoice(): void
    {
        $this->start();

        $this->expectException(TyreChangeRefused::class);
        $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000'),
            self::newTyres([P::FrontLeft]),
            [],
            null,
            self::zone(),
            'en_GB',
        );
    }

    public function testDeletingAMiddleChangeIsRefusedAndDeletingTheLastRemovesTheTyresItCreated(): void
    {
        $existing = $this->start();
        $fit = $this->changes()->fit(
            $this->car,
            new TyreChangeData(self::day('2026-04-10'), '32000.000'),
            self::newTyres([P::FrontLeft, P::FrontRight]),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            null,
            self::zone(),
            'en_GB',
        );

        try {
            $this->changes()->delete($this->car, $existing);
            self::fail('the fit retires tyres the first change put on');
        } catch (TyreChangeRefused $refused) {
            self::assertSame('tyre.error.sequence.not_fitted', $refused->key);
        }
        self::assertSame(6, $this->rows('tyres'));

        self::assertCount(2, $this->changes()->createdBy($this->car, $fit));
        $this->changes()->delete($this->car, $fit);
        self::assertSame(4, $this->rows('tyres'), 'the two new tyres went with it');
        self::assertSame(['fl' => 'Goodyear', 'fr' => 'Goodyear', 'rl' => 'Goodyear', 'rr' => 'Goodyear'], $this->fitted());
        self::assertSame(['tyre 20000.000'], $this->readings());
    }

    public function testDeletingATyreDeletesAChangeLeftWithNoLines(): void
    {
        $this->changes()->existing(
            $this->car,
            new TyreChangeData(self::day('2025-10-03'), '20000.000'),
            self::newTyres([P::Spare]),
            self::zone(),
            'en_GB',
        );
        $spare = $this->tyres()->tyres($this->car)[0];

        $this->changes()->deleteTyre($this->car, $spare);

        self::assertSame(0, $this->rows('tyre_changes'));
        self::assertSame([], $this->readings());
    }

    public function testTheVehicleTypeChangeIsRefusedWhileRearTyresAreFitted(): void
    {
        $this->start();
        $vehicles = $this->service($this->app, VehicleService::class);
        $data = $this->car->data;
        $asBike = new VehicleData(
            VehicleType::Bike,
            $data->make,
            $data->model,
            $data->fuelType,
            registration: $data->registration,
        );

        try {
            $vehicles->update($this->owner($this->app), $this->car, $asBike);
            self::fail('a motorbike has no front left wheel');
        } catch (TyresBlockTypeChange $refused) {
            self::assertSame(P::FrontLeft, $refused->position);
        }
        self::assertSame(VehicleType::Car, $vehicles->get($this->owner($this->app), $this->car->id)->data->type);
    }
}
