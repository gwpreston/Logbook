<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\History;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\FillUpRun;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\History\HistoryPage;
use Logbook\Service\History\Milestone;
use PHPUnit\Framework\TestCase;

/**
 * Folding, months, milestone order and kind chips of the History pages
 * (spec.md §7.16).
 */
final class HistoryPageTest extends TestCase
{
    private static int $id = 0;

    public function testOneFillUpIsNotFoldedTwoOrMoreAre(): void
    {
        $golf = self::vehicle(1);
        self::assertSame([ActivityKind::Fuel], self::shape(HistoryPage::fold([self::fill($golf, '2026-09-17')])));

        $rows = HistoryPage::fold([
            self::fill($golf, '2026-09-17', '60.10', '40'),
            self::fill($golf, '2026-09-10', '70.00', '45.5'),
            self::fill($golf, '2026-09-02', '154.00', '82.9'),
        ]);
        self::assertCount(1, $rows);
        $run = $rows[0];
        self::assertInstanceOf(FillUpRun::class, $run);
        self::assertSame(3, $run->fillUps());
        self::assertSame(0, $run->charges());
        self::assertSame('284.100', $run->total());
        self::assertSame('168.400', $run->volume(), 'one unit: the volumes add up');
        self::assertSame('2026-09-02', $run->oldest()->format('Y-m-d'));
        self::assertSame('2026-09-17', $run->newest()->format('Y-m-d'));
    }

    public function testAnyOtherLineBreaksARun(): void
    {
        $golf = self::vehicle(1);
        $fill = static fn (string $day): ActivityItem => self::fill($golf, $day);

        foreach ([ActivityKind::Maintenance, ActivityKind::Odometer, ActivityKind::Document, ActivityKind::Expense] as $kind) {
            $other = self::item($golf, $kind, '2026-09-15');
            $rows = HistoryPage::fold([$fill('2026-09-20'), $fill('2026-09-18'), $other, $fill('2026-09-10')]);
            self::assertSame(['run', $kind, ActivityKind::Fuel], self::shape($rows), $kind->value);
        }

        $bought = self::item($golf, ActivityKind::Milestone, '2026-09-15', Milestone::Bought);
        self::assertSame(
            [ActivityKind::Fuel, ActivityKind::Milestone, ActivityKind::Fuel],
            self::shape(HistoryPage::fold([$fill('2026-09-20'), $bought, $fill('2026-09-10')])),
        );
    }

    public function testRunsFoldPerVehicleInTheFleetView(): void
    {
        $golf = self::vehicle(1);
        $bike = self::vehicle(2);
        $rows = HistoryPage::fold([
            self::fill($golf, '2026-09-20'),
            self::fill($golf, '2026-09-19'),
            self::fill($bike, '2026-09-18'),
            self::fill($golf, '2026-09-17'),
            self::fill($bike, '2026-09-16'),
            self::fill($bike, '2026-09-15'),
        ]);
        // Another vehicle's fill-up breaks a run.
        self::assertSame(['run', ActivityKind::Fuel, ActivityKind::Fuel, 'run'], self::shape($rows));
        self::assertInstanceOf(FillUpRun::class, $rows[3]);
        self::assertSame($bike->id, $rows[3]->vehicle()->id);
    }

    public function testAPlugInHybridRunCountsFillUpsAndChargesApart(): void
    {
        $outlander = self::vehicle(1, FuelType::Phev);
        $rows = HistoryPage::fold([
            self::fill($outlander, '2026-09-20', '3.00', '10', Fuel::Electricity),
            self::fill($outlander, '2026-09-18', '60.00', '40'),
            self::fill($outlander, '2026-09-15', '3.30', '11', Fuel::Electricity),
            self::fill($outlander, '2026-09-10', '55.00', '38'),
            self::fill($outlander, '2026-09-05', '52.00', '36'),
        ]);
        $run = $rows[0];
        self::assertInstanceOf(FillUpRun::class, $run);
        self::assertSame(3, $run->fillUps());
        self::assertSame(2, $run->charges());
        self::assertNull($run->volume(), 'litres and kWh are never added up');
        self::assertSame('173.300', $run->total());
    }

    public function testNothingFoldsUnderTheFuelChipAndRunsSitUnderTheirNewestMonth(): void
    {
        $golf = self::vehicle(1);
        $items = [self::fill($golf, '2026-09-02'), self::fill($golf, '2026-08-28'), self::fill($golf, '2026-08-20')];

        $plain = HistoryPage::months($items, HistoryChip::Fuel->folds());
        self::assertSame(['2026-09', '2026-08'], array_map(static fn ($m): string => $m->month->format('Y-m'), $plain));
        self::assertCount(2, $plain[1]->rows);

        $folded = HistoryPage::months($items, HistoryChip::Everything->folds());
        self::assertCount(1, $folded, 'the run spans August and September');
        self::assertSame('2026-09', $folded[0]->month->format('Y-m'));
        self::assertInstanceOf(FillUpRun::class, $folded[0]->rows[0]);
    }

    public function testMilestonesSitBelowOrAboveTheirDaysEntries(): void
    {
        $golf = self::vehicle(1);
        $items = [
            self::item($golf, ActivityKind::Milestone, '2019-03-14', Milestone::FirstRegistered),
            self::item($golf, ActivityKind::Maintenance, '2019-03-14'),
            self::item($golf, ActivityKind::Milestone, '2019-03-14', Milestone::Bought),
            self::item($golf, ActivityKind::Milestone, '2019-03-14', Milestone::Sold),
            self::item($golf, ActivityKind::Expense, '2019-03-14'),
        ];
        usort($items, ActivityItem::compare(...));

        self::assertSame(
            ['sold', 'expense', 'maintenance', 'bought', 'first_registered'],
            array_map(static fn (ActivityItem $i): string => $i->milestone->value ?? $i->kind->value, $items),
        );
    }

    public function testChipsHideSwitchedOffModulesAndFallBackToEverything(): void
    {
        $on = [
            'fuel' => true,
            'maintenance' => true,
            'compliance' => true,
            'reminders' => true,
            'reports' => true,
            'tyres' => true,
            'trips' => true,
        ];
        $off = ['fuel' => false, 'maintenance' => true, 'compliance' => false, 'tyres' => false, 'trips' => false] + $on;

        self::assertCount(8, HistoryChip::available($on));
        self::assertSame(HistoryChip::Trips, HistoryChip::available($on)[7], 'Trips last (Phase 22)');
        self::assertNotContains(ActivityKind::Trip, HistoryChip::Everything->kinds(), 'trips never under Everything');
        self::assertSame([ActivityKind::Trip], HistoryChip::Trips->kinds());
        self::assertSame(HistoryChip::Tyres, HistoryChip::available($on)[3], 'Tyres after Fuel');
        self::assertSame(
            [HistoryChip::Everything, HistoryChip::Service, HistoryChip::Expenses, HistoryChip::Mileage],
            HistoryChip::available($off),
        );
        self::assertSame(HistoryChip::Service, HistoryChip::fromQuery('service', $off));
        self::assertSame(HistoryChip::Everything, HistoryChip::fromQuery('fuel', $off), 'a switched-off module');
        self::assertSame(HistoryChip::Everything, HistoryChip::fromQuery('bananas', $on), 'unknown');
        self::assertSame(HistoryChip::Everything, HistoryChip::fromQuery(['fuel'], $on));
        self::assertContains(ActivityKind::Milestone, HistoryChip::Everything->kinds());
        self::assertNotContains(ActivityKind::Milestone, HistoryChip::Service->kinds(), 'milestones under Everything only');
    }

    /**
     * @param list<ActivityItem|FillUpRun> $rows
     * @return list<ActivityKind|string>
     */
    private static function shape(array $rows): array
    {
        return array_map(static fn ($row): ActivityKind|string => $row instanceof FillUpRun ? 'run' : $row->kind, $rows);
    }

    private static function fill(
        Vehicle $vehicle,
        string $day,
        string $cost = '50.00',
        string $volume = '35',
        Fuel $fuel = Fuel::Petrol,
    ): ActivityItem {
        return new ActivityItem(
            kind: ActivityKind::Fuel,
            vehicle: $vehicle,
            entryId: ++self::$id,
            date: self::day($day),
            createdAt: self::day($day),
            label: '',
            labelKey: 'history.kind.fill_up',
            icon: 'local_gas_station',
            amount: $cost,
            currency: 'GBP',
            fuel: $fuel,
            volume: $volume,
        );
    }

    private static function item(Vehicle $vehicle, ActivityKind $kind, string $day, ?Milestone $milestone = null): ActivityItem
    {
        return new ActivityItem(
            kind: $kind,
            vehicle: $vehicle,
            entryId: ++self::$id,
            date: self::day($day),
            createdAt: self::day($day),
            label: '',
            labelKey: 'x',
            icon: 'x',
            milestone: $milestone,
        );
    }

    private static function vehicle(int $id, FuelType $fuel = FuelType::Petrol): Vehicle
    {
        $now = self::day('2026-01-01');

        $data = new VehicleData(VehicleType::Car, 'Make', 'Model ' . $id, $fuel);

        return new Vehicle($id, 1, $data, VehicleStatus::Active, null, null, null, $now, $now);
    }

    private static function day(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }
}
