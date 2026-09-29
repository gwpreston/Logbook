<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Service\Fuel\SegmentCostCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Segment cost (spec.md §7.3, *Fuel insights*): the segment's volume × the
 * burned unit price, that of the opening full fill and the partials inside.
 */
final class SegmentCostCalculatorTest extends TestCase
{
    use BuildsFills;

    public function testASegmentIsCostedAtThePriceOfTheFuelBurnedNotTheRefill(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '1000', '40', '1.600000', FuelGrade::E5_98),
            $this->fillAt('2025-01-10 08:00', '1500', '30', '1.400000', FuelGrade::E10_95),
        ]);

        $costs = SegmentCostCalculator::of($history, EnergyKind::Liquid);

        self::assertCount(1, $costs);
        self::assertSame('1.600000', $costs[0]->unitPrice, 'the E5 in the tank, not the E10 refill');
        self::assertSame('48.000000', $costs[0]->cost, '30 L burned × 1.60');
        self::assertSame('0.09600000', $costs[0]->costPerKm);
        self::assertSame($history->fills[1]->entry->id, $costs[0]->closingEntryId);
    }

    public function testAPartialInsideTheSegmentChangesTheBurnedPrice(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '1000', '30', '1.500000'),
            $this->fillAt('2025-01-05 08:00', '1200', '10', '1.900000', partial: true),
            $this->fillAt('2025-01-10 08:00', '1500', '32', '1.400000'),
        ]);

        $costs = SegmentCostCalculator::of($history, EnergyKind::Liquid);

        // (30 × 1.50 + 10 × 1.90) ÷ 40 = 1.60, over the 42 L the segment burned.
        self::assertSame('1.600000', $costs[0]->unitPrice);
        self::assertSame('67.200000', $costs[0]->cost);
        self::assertSame('0.13440000', $costs[0]->costPerKm);
    }

    public function testAMissedFillUpStartsTheBurnedPriceAgain(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '1000', '30', '1.900000'),
            $this->fillAt('2025-01-03 08:00', '1100', '10', '1.900000', partial: true),
            $this->fillAt('2025-01-06 08:00', '1400', '35', '1.500000', missedPrevious: true),
            $this->fillAt('2025-01-10 08:00', '1900', '32', '1.400000'),
        ]);

        $costs = SegmentCostCalculator::of($history, EnergyKind::Liquid);

        self::assertCount(1, $costs);
        self::assertSame('1.500000', $costs[0]->unitPrice, 'nothing from before the gap');
    }

    public function testFreeChargesCountAtCostZero(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '1000', '40', '0.000000', FuelGrade::Home, total: '0'),
            $this->fillAt('2025-01-04 08:00', '1200', '36', '0.300000', FuelGrade::Home),
            $this->fillAt('2025-01-08 08:00', '1400', '30', '0.000000', FuelGrade::Ac, total: '0'),
        ]);

        $costs = SegmentCostCalculator::of($history, EnergyKind::Electric);

        self::assertCount(2, $costs, 'the free charge opens a segment that still has a cost');
        self::assertSame('0.000000', $costs[0]->cost);
        self::assertSame('0.00000000', $costs[0]->costPerKm);
        self::assertSame('9.000000', $costs[1]->cost, '30 kWh at the 0.30 that went in before');
    }

    public function testTheExistingSegmentFiguresAreUnchanged(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '1000', '30', '1.500000'),
            $this->fillAt('2025-01-05 08:00', '1200', '10', '1.900000', partial: true),
            $this->fillAt('2025-01-10 08:00', '1500', '32', '1.400000'),
        ]);
        $segment = $history->fills[2]->segment;

        self::assertNotNull($segment);
        self::assertSame('42', $segment->volume);
        self::assertSame('63.80', $segment->cost, 'money spent within it: the partial and the closing fill');
        self::assertSame('0.127600', $history->summary(EnergyKind::Liquid)?->costPerKm(), 'the headline figure');
    }

    public function testPetrolAndElectricityNeverMix(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '1000', '30', '1.500000', FuelGrade::E10_95),
            $this->fillAt('2025-01-02 08:00', '1050', '8', '0.250000', FuelGrade::Home),
            $this->fillAt('2025-01-04 08:00', '1100', '8', '0.500000', FuelGrade::DcRapid, partial: true),
            $this->fillAt('2025-01-06 08:00', '1200', '9', '0.250000', FuelGrade::Home),
            $this->fillAt('2025-01-10 08:00', '1500', '20', '1.700000', FuelGrade::E10_95),
        ]);

        $liquid = SegmentCostCalculator::of($history, EnergyKind::Liquid);
        $electric = SegmentCostCalculator::of($history, EnergyKind::Electric);

        self::assertCount(1, $liquid);
        self::assertSame('1.500000', $liquid[0]->unitPrice, 'no charge price in the petrol segment');
        self::assertSame('500', $liquid[0]->distanceKm);
        self::assertCount(1, $electric);
        self::assertSame('0.375000', $electric[0]->unitPrice, '(8 × 0.25 + 8 × 0.50) ÷ 16');
        self::assertSame(Fuel::Electricity, $history->fills[3]->entry->data->fuel);
    }
}
