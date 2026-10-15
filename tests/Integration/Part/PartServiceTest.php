<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Part;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Expense\CostSource;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\PartOrigin;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Part\CameOff;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartRemoval;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordParts;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Parts and modifications through the services against a real database
 * (spec.md §7.39): fitting from a modification, a service record and none,
 * *Record what came off*, fitting again, reverting, deleting a record, the
 * readings they own, distance, *Make this a modification* and the ledger.
 */
final class PartServiceTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private Vehicle $golf;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->pinClock($this->app, '2026-10-14T10:00:00Z');
        $this->golf = $this->vehicle($this->app);
    }

    public function testAModificationFitsNewPartsAndRecordsWhatCameOffInOneSave(): void
    {
        $modification = $this->modify('Coilovers', '1240', '60000', new RecordParts(
            fitNew: [new PartData('B14 coilover kit', brand: 'Bilstein', price: '899.000'), new PartData('Top mounts')],
            cameOff: [new CameOff(new PartData('Standard dampers', brand: 'Sachs'))],
        ));

        $parts = $this->byName();
        self::assertSame(PartStatus::Fitted, $parts['B14 coilover kit']->status);
        self::assertSame(PartStatus::Fitted, $parts['Top mounts']->status);
        self::assertSame(PartStatus::Stored, $parts['Standard dampers']->status, 'kept by default');
        self::assertSame(PartOrigin::Original, $parts['Standard dampers']->data->origin);

        $kit = $this->parts()->fittings($parts['B14 coilover kit']);
        self::assertCount(1, $kit);
        self::assertSame($modification->id, $kit[0]->fittedModificationId);
        self::assertSame('2026-05-10', $kit[0]->fittedOn->format('Y-m-d'));
        self::assertSame('60000.000', $kit[0]->fittedOdometerKm);
        $dampers = $this->parts()->fittings($parts['Standard dampers'])[0];
        self::assertSame($modification->id, $dampers->removedModificationId);
        self::assertSame(RemovalOutcome::Kept, $dampers->outcome);
        self::assertNull($dampers->fittedOdometerKm, 'on the car before it was logged');

        // One reading: the modification's. Its fittings use it.
        $sources = array_map(static fn ($r): string => $r->source->value, $this->readings());
        self::assertSame(['modification'], $sources);
    }

    public function testRecordWhatCameOffStartsAtThePurchaseDate(): void
    {
        $vehicle = $this->service($this->app, \Logbook\Service\Vehicle\VehicleService::class)->update(
            $this->owner,
            $this->golf,
            new \Logbook\Domain\Vehicle\VehicleData(
                $this->golf->data->type,
                'Volkswagen',
                'Golf',
                $this->golf->data->fuelType,
                registration: 'GO19 ABC',
                purchaseDate: self::day('2021-03-14'),
            ),
        );
        $this->golf = $vehicle;
        $this->modify('Exhaust', '0', null, new RecordParts(cameOff: [new CameOff(new PartData('Standard exhaust'))]));

        $fitting = $this->parts()->fittings($this->byName()['Standard exhaust'])[0];
        self::assertSame('2021-03-14', $fitting->fittedOn->format('Y-m-d'));
    }

    public function testAServiceRecordFitsAPartWhichFollowsItsDateAndGoesWithIt(): void
    {
        $record = $this->record('2026-06-01', '780', '61000', new RecordParts(
            fitNew: [new PartData('Clutch kit', warrantyMonths: 24, warrantyFrom: WarrantyStart::Fitting)],
        ));
        $clutch = $this->byName()['Clutch kit'];
        self::assertSame(PartStatus::Fitted, $clutch->status);
        self::assertSame($record->id, $this->parts()->fittings($clutch)[0]->fittedMaintenanceId);

        // Editing the record without its lists moves the fitting with it.
        $data = $record->data;
        $this->maintenanceService()->update($this->golf, $record, new MaintenanceEntryData(
            self::day('2026-06-03'),
            $data->category,
            $data->title,
            $data->cost,
            '61050.000',
        ), self::zone());
        $fitting = $this->parts()->fittings($clutch)[0];
        self::assertSame('2026-06-03', $fitting->fittedOn->format('Y-m-d'));
        self::assertSame('61050.000', $fitting->fittedOdometerKm);

        // Deleting it takes the fitting away; the part stays, stored.
        $saved = $this->maintenanceService()->get($this->golf, $record->id);
        $this->maintenanceService()->delete($this->golf, $saved, self::zone());
        $clutch = $this->byName()['Clutch kit'];
        self::assertSame(PartStatus::Stored, $clutch->status);
        self::assertSame([], $this->parts()->fittings($clutch));
    }

    public function testUntickingAPartOnTheRecordTakesTheFittingOrTheRemovalBack(): void
    {
        $spare = $this->addPart('Spare wheel');
        $fitted = $this->addPart('Roof bars', fitOn: '2026-01-10', km: '50000');
        $record = $this->record('2026-06-01', '50', null, new RecordParts(
            fit: [$spare->id],
            remove: [$fitted->id => new PartRemoval()],
        ));
        self::assertSame(PartStatus::Fitted, $this->part($spare)->status);
        self::assertSame(PartStatus::Stored, $this->part($fitted)->status);

        $this->maintenanceService()->update($this->golf, $record, $record->data, self::zone(), parts: new RecordParts());
        self::assertSame(PartStatus::Stored, $this->part($spare)->status);
        self::assertSame(PartStatus::Fitted, $this->part($fitted)->status, 'the removal is reopened');
    }

    public function testFittingWithoutARecordWritesItsOwnReadingsAndAPartCanGoBackOn(): void
    {
        $exhaust = $this->addPart('Standard exhaust', fitOn: '2026-01-10', km: '50000');
        self::assertSame(PartStatus::Fitted, $exhaust->status);
        $this->parts()->remove($this->golf, $exhaust, self::day('2026-03-01'), '52000.000', new PartRemoval(), self::zone());
        $exhaust = $this->part($exhaust);
        self::assertSame(PartStatus::Stored, $exhaust->status);

        $this->parts()->fit($this->golf, $exhaust, self::day('2026-09-01'), '58000.000', self::zone());
        $exhaust = $this->part($exhaust);
        self::assertSame(PartStatus::Fitted, $exhaust->status);
        self::assertCount(2, $this->parts()->fittings($exhaust));

        $sources = array_map(static fn ($r): string => $r->source->value, $this->readings());
        self::assertSame(['part_fitted', 'part_removed', 'part_fitted'], $sources);

        // Two spells: 2,000 km, then 58,000 to the latest reading.
        $this->reading($this->app, $this->golf, '59500', '2026-10-01T12:00:00Z');
        $distance = $this->parts()->distance($this->golf, $this->parts()->fittings($exhaust), self::zone());
        self::assertSame('3500.000', $distance->km);
        self::assertFalse($distance->atLeast);

        // Deleting the part takes its readings with it.
        $this->parts()->delete($this->golf, $exhaust);
        $sources = array_map(static fn ($r): string => $r->source->value, $this->readings());
        self::assertSame(['manual'], $sources);
    }

    public function testOnlyAStoredPartCanBeFittedAndAGonePartNeverComesBack(): void
    {
        $exhaust = $this->addPart('Standard exhaust', fitOn: '2026-01-10', km: '50000');
        try {
            $this->parts()->fit($this->golf, $exhaust, self::day('2026-02-01'), null, self::zone());
            self::fail('a fitted part was fitted again');
        } catch (PartChangeRefused $refused) {
            self::assertSame('part.refused.not_stored', $refused->key);
        }

        $this->parts()->remove(
            $this->golf,
            $exhaust,
            self::day('2026-03-01'),
            null,
            new PartRemoval(RemovalOutcome::Scrapped),
            self::zone(),
        );
        self::assertSame(PartStatus::Gone, $this->part($exhaust)->status);
        $this->expectException(PartChangeRefused::class);
        $this->parts()->fit($this->golf, $this->part($exhaust), self::day('2026-04-01'), null, self::zone());
    }

    public function testARemovalBeforeTheFittingIsRefused(): void
    {
        $exhaust = $this->addPart('Standard exhaust', fitOn: '2026-05-10', km: '50000');
        try {
            $this->parts()->remove($this->golf, $exhaust, self::day('2026-05-01'), null, new PartRemoval(), self::zone());
            self::fail('removed before it was fitted');
        } catch (PartChangeRefused $refused) {
            self::assertSame('part.refused.removed_before_fitted', $refused->key);
            self::assertSame('Standard exhaust', $refused->params['part']);
        }
        self::assertSame(PartStatus::Fitted, $this->part($exhaust)->status, 'nothing was written');
    }

    public function testDeletingARecordThatWouldLeaveFittingsOverlappingIsRefused(): void
    {
        $exhaust = $this->addPart('Standard exhaust', fitOn: '2026-01-10', km: '50000');
        $modification = $this->modify('Cat-back exhaust', '600', null, new RecordParts(
            fitNew: [new PartData('Cat-back')],
            remove: [$exhaust->id => new PartRemoval()],
        ), '2026-03-01');
        // Back on later, without a record.
        $this->parts()->fit($this->golf, $this->part($exhaust), self::day('2026-08-01'), null, self::zone());

        try {
            $this->modifications()->delete($this->golf, $modification);
            self::fail('deleting it would have left the exhaust on twice');
        } catch (PartChangeRefused $refused) {
            self::assertSame('part.refused.overlap', $refused->key);
        }
        self::assertNotNull($this->modifications()->find($this->golf, $modification->id), 'nothing was deleted');
    }

    public function testRevertingTakesTheBuildOffAndPutsTheOriginalBackAndUndoingPutsItAllBack(): void
    {
        $modification = $this->modify('Cat-back exhaust', '600', '60000', new RecordParts(
            fitNew: [new PartData('Cat-back')],
            cameOff: [new CameOff(new PartData('Standard exhaust'))],
        ));
        $parts = $this->byName();

        $reverted = $this->modifications()->revert(
            $this->golf,
            $modification,
            self::day('2026-09-01'),
            '64000.000',
            'Back to standard to sell',
            [$parts['Cat-back']->id => new PartRemoval()],
            [$parts['Standard exhaust']->id],
            self::zone(),
        );
        self::assertTrue($reverted->isReverted());
        $parts = $this->byName();
        self::assertSame(PartStatus::Stored, $parts['Cat-back']->status);
        self::assertSame(PartStatus::Fitted, $parts['Standard exhaust']->status);
        self::assertContains('mod_revert', array_map(static fn ($r): string => $r->source->value, $this->readings()));

        // Still in the ledger: it was spent.
        self::assertCount(1, $this->buildLines());

        $undone = $this->modifications()->undoRevert($this->golf, $reverted);
        self::assertFalse($undone->isReverted());
        $parts = $this->byName();
        self::assertSame(PartStatus::Fitted, $parts['Cat-back']->status);
        self::assertSame(PartStatus::Stored, $parts['Standard exhaust']->status);
        $sources = array_map(static fn ($r): string => $r->source->value, $this->readings());
        self::assertNotContains('mod_revert', $sources);
    }

    /**
     * A part the revert put back that has come off again since (here sold)
     * has history of its own: *Undo revert* is refused rather than erase it
     * (spec.md §7.39, #432).
     */
    public function testUndoRevertIsRefusedOnceAPartItPutBackHasComeOffAgain(): void
    {
        $modification = $this->modify('Cat-back exhaust', '600', '60000', new RecordParts(
            fitNew: [new PartData('Cat-back')],
            cameOff: [new CameOff(new PartData('Standard exhaust'))],
        ));
        $parts = $this->byName();
        $reverted = $this->modifications()->revert(
            $this->golf,
            $modification,
            self::day('2026-09-01'),
            null,
            null,
            [$parts['Cat-back']->id => new PartRemoval()],
            [$parts['Standard exhaust']->id],
            self::zone(),
        );
        $exhaust = $this->part($this->byName()['Standard exhaust']);
        $this->parts()->remove(
            $this->golf,
            $exhaust,
            self::day('2026-09-10'),
            null,
            new PartRemoval(RemovalOutcome::Sold, '120.000'),
            self::zone(),
        );

        try {
            $this->modifications()->undoRevert($this->golf, $reverted);
            self::fail('undoing would erase the sale');
        } catch (PartChangeRefused $refused) {
            self::assertSame('modification.refused.undo_changed_since', $refused->key);
            self::assertSame('Standard exhaust', $refused->params['part']);
        }
        $fittings = $this->parts()->fittings($this->part($exhaust));
        $last = $fittings[count($fittings) - 1];
        self::assertSame(RemovalOutcome::Sold, $last->outcome, 'the sale stays');
        self::assertSame('120.000', $last->soldPrice);
        self::assertTrue($this->modifications()->get($this->golf, $reverted->id)->isReverted(), 'still reverted');
    }

    public function testTheLedgerHasTheBuildAndASoldPartInTheGroupOfWhatFittedIt(): void
    {
        $this->modify('Remap', '450', null, null);
        $this->modify('Free sticker', '0', null, null);
        $this->modify('Wheels', '1200', null, new RecordParts(
            fitNew: [new PartData('Alloy wheels')],
            cameOff: [new CameOff(new PartData('Standard wheels'), new PartRemoval(RemovalOutcome::Sold, '300.000'))],
        ));
        $this->record('2026-06-01', '780', null, new RecordParts(fitNew: [new PartData('Clutch kit')]));
        $clutch = $this->byName()['Clutch kit'];
        $sold = new PartRemoval(RemovalOutcome::Sold, '50.000');
        $this->parts()->remove($this->golf, $clutch, self::day('2026-09-01'), null, $sold, self::zone());

        $lines = $this->buildLines();
        $byTitle = [];
        foreach ($lines as $line) {
            $byTitle[$line->title] = [$line->group(), $line->amount->toDecimal(2)];
        }
        self::assertSame([CostGroup::Build, '450.00'], $byTitle['Remap']);
        self::assertSame([CostGroup::Build, '1200.00'], $byTitle['Wheels']);
        self::assertArrayNotHasKey('Free sticker', $byTitle, 'a free modification is history, not a cost');
        self::assertSame([CostGroup::Build, '-300.00'], $byTitle['Standard wheels'], 'original parts in build');
        self::assertSame([CostGroup::Maintenance, '-50.00'], $byTitle['Clutch kit'], 'fitted by a service record');

        // Switched off, the build lines leave the ledger.
        $this->service($this->app, SettingRepository::class)->save(FeatureToggles::SETTING, ['parts' => false]);
        self::assertSame([], $this->buildLines());
    }

    public function testCostOfOwnershipCountsTheBuildOnceAndNeverInARate(): void
    {
        $this->reading($this->app, $this->golf, '10000', '2026-01-02T12:00:00Z');
        $this->maintenance($this->app, $this->golf, '2026-03-01', 'Service', '200.000', '11000.000');
        $this->reading($this->app, $this->golf, '20000', '2026-10-01T12:00:00Z');
        $without = $this->trueCost();

        $this->modify('Remap', '450', null, null, '2026-04-01');
        $with = $this->trueCost();

        $before = $without->ownership;
        $after = $with->ownership;
        self::assertSame($before->running->toDecimal(2), $after->running->toDecimal(2), 'running costs leave the build out');
        self::assertSame('450.00', $after->build?->toDecimal(2));
        self::assertSame($before->runningPerKm, $after->runningPerKm, 'the rate per distance is unchanged');
        self::assertSame($before->runningPerMonth?->toDecimal(2), $after->runningPerMonth?->toDecimal(2));
        $build = array_values(array_filter($after->groups, static fn ($g): bool => $g->group === CostGroup::Build));
        self::assertSame('450.00', $build[0]->amount->toDecimal(2), 'its own group in the total');

        // True cost: no part for the build, the same per-distance figure.
        foreach (TruePart::running() as $part) {
            self::assertSame(
                $without->sinceBought->amount($part)?->toDecimal(2),
                $with->sinceBought->amount($part)?->toDecimal(2),
                $part->value,
            );
        }
        self::assertSame($without->sinceBought->perKm, $with->sinceBought->perKm);
        self::assertSame($without->lastTwelveMonths?->perKm, $with->lastTwelveMonths?->perKm);
    }

    public function testAServiceRecordBecomesAModificationWithItsFiles(): void
    {
        $record = $this->record('2026-04-01', '899', '55000', null, 'Cat-back exhaust fitted');
        $on = self::day('2026-04-01');
        $data = new ModificationData('Cat-back exhaust', ModificationCategory::Exhaust, $on, '55000.000', '899.000');
        $modification = $this->modifications()->fromMaintenance($this->golf, $record, $data, self::zone());

        self::assertNull($this->maintenanceService()->find($this->golf, $record->id));
        self::assertSame('899.000', $modification->data->cost);
        $sources = array_map(static fn ($r): string => $r->source->value, $this->readings());
        self::assertSame(['modification'], $sources, 'the reading moved to the modification');
        $groups = array_map(static fn (CostItem $i): CostGroup => $i->group(), $this->ledger());
        self::assertSame([CostGroup::Build], $groups);
    }

    public function testARecordWithLinksAModificationCannotHoldIsNotConverted(): void
    {
        $record = $this->record('2026-04-01', '100', null, new RecordParts(fitNew: [new PartData('Filter')]));
        self::assertSame('modification.convert.refused.parts', $this->modifications()->refusalFor($this->golf, $record));
        $this->expectException(PartChangeRefused::class);
        $this->modifications()->fromMaintenance(
            $this->golf,
            $record,
            new ModificationData('Filter', ModificationCategory::Other, self::day('2026-04-01')),
            self::zone(),
        );
    }

    public function testAnExpenseBecomesAModification(): void
    {
        $expense = $this->expense($this->app, $this->golf, '2026-02-01', '35.00', ExpenseCategory::Accessories, 'LED bulbs');
        $this->modifications()->fromExpense(
            $this->golf,
            $expense,
            new ModificationData('LED bulbs', ModificationCategory::Lighting, self::day('2026-02-01'), cost: '35.000'),
            self::zone(),
        );
        $sources = array_map(static fn (CostItem $i): CostSource => $i->source, $this->ledger());
        self::assertSame([CostSource::Modification], $sources);
    }

    private function modify(
        string $title,
        string $cost,
        ?string $km,
        ?RecordParts $parts,
        string $on = '2026-05-10',
    ): Modification {
        $km = $km === null ? null : $km . '.000';
        $data = new ModificationData($title, ModificationCategory::Other, self::day($on), $km, $cost . '.000');

        return $this->modifications()->create($this->golf, $data, self::zone(), parts: $parts);
    }

    private function record(
        string $on,
        string $cost,
        ?string $km,
        ?RecordParts $parts,
        string $title = 'Work',
    ): MaintenanceEntry {
        $km = $km === null ? null : $km . '.000';
        $data = new MaintenanceEntryData(self::day($on), MaintenanceCategory::Repair, $title, $cost . '.000', $km);

        return $this->maintenanceService()->create($this->golf, $data, self::zone(), parts: $parts);
    }

    private function addPart(string $name, ?string $fitOn = null, ?string $km = null): Part
    {
        return $this->parts()->create(
            $this->golf,
            new PartData($name),
            self::zone(),
            fitOn: $fitOn === null ? null : self::day($fitOn),
            fitKm: $km === null ? null : $km . '.000',
        );
    }

    private function part(Part $part): Part
    {
        return $this->parts()->get($this->golf, $part->id);
    }

    /**
     * @return array<string, Part>
     */
    private function byName(): array
    {
        $parts = [];
        foreach ($this->parts()->list($this->golf) as $part) {
            $parts[$part->data->name] = $part;
        }

        return $parts;
    }

    /**
     * @return list<\Logbook\Domain\Odometer\OdometerReading>
     */
    private function readings(): array
    {
        return $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($this->golf->id);
    }

    private function trueCost(): VehicleTrueCost
    {
        $cost = $this->service(
            $this->app,
            TrueCostService::class
        )->forVehicle(
            $this->owner,
            $this->golf,
            self::day('2026-10-14'),
        );
        self::assertNotNull($cost);

        return $cost;
    }

    /**
     * @return list<CostItem>
     */
    private function ledger(): array
    {
        return $this->service($this->app, CostLedger::class)->items($this->owner, [$this->golf]);
    }

    /**
     * @return list<CostItem>
     */
    private function buildLines(): array
    {
        return array_values(array_filter(
            $this->ledger(),
            static fn (CostItem $i): bool => in_array($i->source, [CostSource::Modification, CostSource::PartSale], true),
        ));
    }

    private function parts(): PartService
    {
        return $this->service($this->app, PartService::class);
    }

    private function modifications(): ModificationService
    {
        return $this->service($this->app, ModificationService::class);
    }

    private function maintenanceService(): MaintenanceService
    {
        return $this->service($this->app, MaintenanceService::class);
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone('Europe/London');
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }
}
