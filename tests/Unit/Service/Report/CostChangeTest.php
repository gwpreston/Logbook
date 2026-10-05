<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\ChangeCause;
use Logbook\Service\Report\ChangeLine;
use Logbook\Service\Report\CostChange;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostPeriod;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TruePart;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use PHPUnit\Framework\TestCase;

/**
 * *What changed* (docs/phases/phase-32.md): each year's change split into
 * contributions that add up exactly to it.
 */
final class CostChangeTest extends TestCase
{
    use TrueCostFixtures;

    private const string NONE_SMALL = '0';

    public function testAPriceRiseWithBetterEconomy(): void
    {
        // 2024: 700 L at £1.50 over 10,000 km. 2025: 650 L at £1.60 over 10,000 km.
        $car = self::vehicle('2023-01-01', '15000.000');
        $change = self::change($car, [
            self::fill($car, '2024-06-01T12:00:00Z', '1050.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '1040.000', '650.000'),
        ], ['5000', '15000', '25000']);

        self::assertNotNull($change);
        self::assertSame('-0.001000', $change->perKm, '£0.105 → £0.104 a km');
        self::assertAddsUp($change);
        $fuel = self::line($change, ChangeCause::Part, TruePart::Fuel);
        self::assertSame('-0.001000', $fuel->perKm);
        self::assertCount(2, $fuel->details);
        [$price, $economy] = [self::detail($fuel, ChangeCause::Price), self::detail($fuel, ChangeCause::Economy)];
        self::assertSame('0.007000', $price->perKm, '+£0.10 a litre × 0.07 L a km');
        self::assertSame('0.066667', $price->fraction, '7% more per litre');
        self::assertSame('-0.008000', $economy->perKm, '−0.005 L a km × £1.60');
        self::assertSame('-0.071429', $economy->fraction, 'economy improved 7%');
        self::assertSame(EnergyKind::Liquid, $price->energy);
    }

    public function testLessDrivingWithTheSameInsurance(): void
    {
        // £500 of insurance both years; 10,000 km, then 8,000 km.
        $car = self::vehicle('2023-01-01', '15000.000');
        $change = self::change($car, [
            self::document($car, '2024-03-01', '500.000'),
            self::document($car, '2025-03-01', '500.000'),
            self::fill($car, '2024-06-01T12:00:00Z', '1000.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '800.000', '560.000'),
        ], ['5000', '15000', '23000']);

        self::assertNotNull($change);
        self::assertSame('0.012500', $change->perKm, '£0.15 → £0.1625 a km');
        self::assertAddsUp($change);
        $distance = self::line($change, ChangeCause::Distance, TruePart::Documents);
        self::assertSame('0.012500', $distance->perKm, '£500 ÷ 8,000 − £500 ÷ 10,000');
        self::assertSame('-2000', $distance->distanceKm, '"because you drove 2,000 km less"');
        self::assertNull(self::find($change, ChangeCause::Amount, TruePart::Documents), 'the same amount: no amount line');
        self::assertNull(self::find($change, ChangeCause::Part, TruePart::Fuel), 'fuel per km unchanged');
    }

    public function testADocumentsChangeSplitsIntoDistanceAndAmount(): void
    {
        $car = self::vehicle('2023-01-01', '15000.000');
        $change = self::change($car, [
            self::document($car, '2024-03-01', '500.000'),
            self::document($car, '2025-03-01', '620.000'),
            self::fill($car, '2024-06-01T12:00:00Z', '1000.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '900.000', '600.000'),
        ], ['5000', '15000', '23000']);

        self::assertNotNull($change);
        self::assertAddsUp($change);
        $byDistance = self::line($change, ChangeCause::Distance, TruePart::Documents);
        $byAmount = self::line($change, ChangeCause::Amount, TruePart::Documents);
        self::assertSame('0.015500', $byDistance->perKm, '£620 ÷ 8,000 − £620 ÷ 10,000');
        self::assertSame('0.012000', $byAmount->perKm, '£120 more ÷ 10,000');
        self::assertSame('120.000', $byAmount->amountChange?->toDecimal(3));
    }

    public function testDepreciationGetsADistanceLineToo(): void
    {
        // £15,000 on 1 Jan 2024, £12,000 on 1 Jan 2025, £9,600 on 1 Jan 2026.
        $car = self::vehicle('2024-01-01', '15000.000');
        $valuations = [self::valuation('2025-01-01', '12000.000'), self::valuation('2026-01-01', '9600.000', 2)];
        $change = self::change($car, [
            self::fill($car, '2024-06-01T12:00:00Z', '1000.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '800.000', '560.000'),
        ], ['0', '10000', '18000'], $valuations, '2024-01-01');

        self::assertNotNull($change);
        self::assertAddsUp($change);
        self::assertSame('0.300000', $change->before->rate(TruePart::Depreciation), '£3,000 ÷ 10,000 km');
        self::assertSame('0.300000', $change->after->rate(TruePart::Depreciation), '£2,400 ÷ 8,000 km');
        self::assertSame('0.060000', self::line($change, ChangeCause::Distance, TruePart::Depreciation)->perKm, '£2,400 ÷ 8,000 − £2,400 ÷ 10,000');
        self::assertSame('-0.060000', self::line($change, ChangeCause::Amount, TruePart::Depreciation)->perKm, '£600 less ÷ 10,000');
    }

    public function testAPlugInHybridIsSplitPerEnergy(): void
    {
        $car = self::vehicle('2023-01-01', '15000.000');
        $change = self::change($car, [
            self::fill($car, '2024-06-01T12:00:00Z', '600.000', '400.000'),
            self::fill($car, '2024-07-01T12:00:00Z', '300.000', '1500.000', Fuel::Electricity),
            self::fill($car, '2025-06-01T12:00:00Z', '520.000', '325.000'),
            self::fill($car, '2025-07-01T12:00:00Z', '420.000', '1750.000', Fuel::Electricity),
        ], ['5000', '15000', '25000']);

        self::assertNotNull($change);
        self::assertAddsUp($change);
        $fuel = self::line($change, ChangeCause::Part, TruePart::Fuel);
        self::assertCount(4, $fuel->details, 'a price and an economy line for each energy');
        self::assertSame(
            [EnergyKind::Liquid, EnergyKind::Liquid, EnergyKind::Electric, EnergyKind::Electric],
            array_map(static fn (ChangeLine $l): ?EnergyKind => $l->energy, $fuel->details),
        );
    }

    public function testAnEnergyBoughtInOneYearOnlyIsOneLine(): void
    {
        $car = self::vehicle('2023-01-01', '15000.000');
        $change = self::change($car, [
            self::fill($car, '2024-06-01T12:00:00Z', '1000.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '700.000', '480.000'),
            self::fill($car, '2025-07-01T12:00:00Z', '210.000', '700.000', Fuel::Electricity),
        ], ['5000', '15000', '25000']);

        self::assertNotNull($change);
        self::assertAddsUp($change);
        $fuel = self::line($change, ChangeCause::Part, TruePart::Fuel);
        $energy = self::detail($fuel, ChangeCause::Energy);
        self::assertSame(EnergyKind::Electric, $energy->energy);
        self::assertSame('0.021000', $energy->perKm, '£210 of charging ÷ 10,000 km');
    }

    public function testSmallChangesAreGroupedLastAndTheTotalStaysExact(): void
    {
        $car = self::vehicle('2023-01-01', '15000.000');
        $items = [
            self::fill($car, '2024-06-01T12:00:00Z', '1000.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '1300.000', '700.000'),
            self::maintenance($car, '2024-05-01', '200.000'),
            self::maintenance($car, '2025-05-01', '210.000'),
            self::expense($car, '2024-05-01', '30.000'),
            self::expense($car, '2025-05-01', '33.000'),
        ];

        $change = self::change($car, $items, ['5000', '15000', '25000'], small: TrueCostService::smallPerKm('GBP', DistanceUnit::Mile));

        self::assertNotNull($change);
        self::assertAddsUp($change);
        $last = $change->lines[count($change->lines) - 1];
        self::assertSame(ChangeCause::Small, $last->cause, '£0.001 and £0.0003 a km are under 0.2p a mile');
        self::assertCount(2, $last->details);
        self::assertSame('0.001300', $last->perKm);
        self::assertSame(ChangeCause::Part, $change->lines[0]->cause, 'the fuel price rise first');
    }

    public function testYearsUnder500KmAreNotCompared(): void
    {
        $car = self::vehicle('2023-01-01', '15000.000');

        $change = self::change($car, [
            self::fill($car, '2024-06-01T12:00:00Z', '1000.000', '700.000'),
            self::fill($car, '2025-06-01T12:00:00Z', '30.000', '20.000'),
        ], ['5000', '15000', '15400']);

        self::assertNull($change);
    }

    public function testThresholdIsPointTwoOfTheSmallestUnitPerDistanceUnit(): void
    {
        self::assertSame('0.002000', TrueCostService::smallPerKm('GBP', DistanceUnit::Kilometre));
        self::assertSame('0.001242742', TrueCostService::smallPerKm('GBP', DistanceUnit::Mile), '0.2p a mile');
        self::assertSame('0.200000', TrueCostService::smallPerKm('JPY', DistanceUnit::Kilometre), 'a currency without minor units');
    }

    /**
     * 2024 against 2025, with readings at the end of 2023, 2024 and 2025.
     *
     * @param list<CostItem> $items
     * @param array{0: string, 1: string, 2: string} $odometer
     * @param list<VehicleValuation> $valuations
     */
    private static function change(
        Vehicle $car,
        array $items,
        array $odometer,
        array $valuations = [],
        string $firstReading = '2023-01-01',
        string $small = self::NONE_SMALL,
    ): ?CostChange {
        $readings = [
            self::reading(1, '0', $firstReading . 'T00:00:00Z'),
            self::reading(2, $odometer[0], '2023-12-31T12:00:00Z'),
            self::reading(3, $odometer[1], '2024-12-31T12:00:00Z'),
            self::reading(4, $odometer[2], '2025-12-31T12:00:00Z'),
        ];
        if ($firstReading === '2024-01-01') {
            $readings = [$readings[0], $readings[2], $readings[3]];
        }
        $years = [];
        foreach (TrueCostPeriod::years(self::date('2024-01-01'), self::date('2025-12-31')) as $year) {
            $years[] = self::period($car, $year, $items, $readings, $valuations);
        }

        return CostChange::between($years[0], $years[1], $small);
    }

    private static function assertAddsUp(CostChange $change): void
    {
        self::assertNotNull($change->before->perKm);
        self::assertNotNull($change->after->perKm);
        self::assertSame(Decimal::subtract($change->after->perKm, $change->before->perKm), $change->perKm);
        $sum = '0.000000';
        foreach ($change->lines as $line) {
            $sum = Decimal::add($sum, $line->perKm);
            if ($line->details !== []) {
                $details = '0.000000';
                foreach ($line->details as $detail) {
                    $details = Decimal::add($details, $detail->perKm);
                }
                self::assertSame(Decimal::round($line->perKm, 6), Decimal::round($details, 6), 'a line\'s details add up to it');
            }
        }
        self::assertSame(Decimal::round($change->perKm, 6), Decimal::round($sum, 6), 'the lines add up to the change');
    }

    private static function line(CostChange $change, ChangeCause $cause, TruePart $part): ChangeLine
    {
        $line = self::find($change, $cause, $part);
        self::assertNotNull($line);

        return $line;
    }

    private static function find(CostChange $change, ChangeCause $cause, TruePart $part): ?ChangeLine
    {
        foreach ($change->lines as $line) {
            if ($line->cause === $cause && $line->part === $part) {
                return $line;
            }
        }

        return null;
    }

    private static function detail(ChangeLine $line, ChangeCause $cause): ChangeLine
    {
        foreach ($line->details as $detail) {
            if ($detail->cause === $cause) {
                return $detail;
            }
        }
        self::fail('No ' . $cause->value . ' line.');
    }
}
