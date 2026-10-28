<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Working;

use Logbook\Service\Report\CostChange;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostPeriod;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Service\Working\OwnershipWorking;
use Logbook\Service\Working\TrueCostWorking;
use Logbook\Support\Working;
use Logbook\Tests\Support\WorkingWords;
use Logbook\Tests\Unit\Service\Report\TrueCostFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The working of true cost and of the depreciation in it (spec.md §7.35, §8
 * *Show the working*). A Golf bought for £15,000 on 1 Mar 2023, valued
 * £12,000 on 1 Jan 2026 and £10,000 on 1 Jul 2026 (181 days): the value
 * falls £2,000 over those days in a straight line, and Logbook never
 * extends the line past the last value it was given.
 */
final class TrueCostWorkingTest extends TestCase
{
    use TrueCostFixtures;

    public function testAPeriodOnTwoValuesIsTheirDifference(): void
    {
        [$vtc, $cost] = $this->fixture('2026-01-01', '2026-06-30');

        $working = $this->builder()->depreciation($vtc, $cost, self::date('2026-09-01'));

        self::assertNotNull($working);
        self::assertSame('2000.000000', $working->resultValue);
        self::assertSame($cost->depreciation?->toDecimal(6), $working->resultValue);
        self::assertSame(
            'Value on 1 Jan 2026 £12,000.00 (your valuation of 1 Jan 2026, £12,000.00)'
            . ' − value at the end of 30 Jun 2026 £10,000.00 (your valuation of 1 Jul 2026, £10,000.00) = £2,000.00.',
            $working->steps[0],
        );
        self::assertStringContainsString('only draws a straight line between values you entered', $working->notes[0]);
        self::assertCount(2, $working->inputs, 'the two values, each once');
        self::assertSame('valuation', $working->inputs[0]->asOf?->kind->value);
    }

    public function testAPeriodBetweenValuesIsReadOffTheStraightLine(): void
    {
        [$vtc, $cost] = $this->fixture('2026-02-01', '2026-04-30');

        $working = $this->builder()->depreciation($vtc, $cost, self::date('2026-09-01'));

        self::assertNotNull($working);
        // 12,000 − 2,000 × 31/181 = 11,657.459; 12,000 − 2,000 × 120/181 = 10,674.033; the loss 983.425.
        self::assertSame('983.425414', $working->resultValue);
        self::assertSame($cost->depreciation?->toDecimal(6), $working->resultValue);
        self::assertStringContainsString(
            'Value on 1 Feb 2026 £11,657.46 (straight line between your valuation of 1 Jan 2026, £12,000.00'
            . ' and your valuation of 1 Jul 2026, £10,000.00)',
            $working->steps[0],
        );
        self::assertStringContainsString('= £983.43.', $working->steps[0]);
    }

    public function testAPeriodPastTheLatestValueIsMeasuredOnlyToIt(): void
    {
        [$vtc, $cost] = $this->fixture('2026-01-01', '2026-12-31');

        $working = $this->builder()->depreciation($vtc, $cost, self::date('2026-09-01'));

        self::assertNotNull($working);
        self::assertSame('2000.000000', $working->resultValue, 'the line stops at 1 Jul');
        self::assertStringContainsString('value on 1 Jul 2026 £10,000.00', $working->steps[0]);
        self::assertStringContainsString('Measured only up to your latest value, 1 Jul 2026', implode(' ', $working->notes));
    }

    public function testTheCostOfAPeriodNamesItsPartsDistanceAndRate(): void
    {
        [$vtc, $cost] = $this->fixture('2026-01-01', '2026-06-30');

        $working = $this->builder()->period($vtc, $cost, self::date('2026-09-01'));

        self::assertNotNull($working);
        self::assertNotNull($cost->perKm);
        self::assertSame($cost->perKm, $working->resultValue);
        self::assertStringContainsString('Maintenance £500.00', $working->steps[0]);
        self::assertStringContainsString('+ Depreciation £2,000.00 = £2,500.00.', $working->steps[0]);
        self::assertStringContainsString('between your odometer readings of', implode(' ', $working->steps));
        self::assertStringContainsString('Together:', implode(' ', $working->steps));
        $labels = array_map(static fn ($input): string => $input->label, $working->inputs);
        self::assertContains('Maintenance', $labels);
        self::assertContains('Depreciation', $labels);
        self::assertContains('Distance driven', $labels);
    }

    public function testWhatChangedSetsTheTwoPeriodsSideBySideAndAddsUp(): void
    {
        $golf = self::vehicle('2023-03-01', '15000.000');
        $items = [
            self::maintenance($golf, '2025-03-01', '400.000'),
            self::maintenance($golf, '2026-03-01', '700.000'),
        ];
        $readings = [
            self::reading(1, '10000', '2023-03-01T12:00:00Z'),
            self::reading(2, '20000', '2024-12-31T12:00:00Z'),
            self::reading(3, '30000', '2025-12-31T12:00:00Z'),
            self::reading(4, '38000', '2026-08-31T12:00:00Z'),
        ];
        $years = TrueCostPeriod::years(self::date('2023-03-01'), self::date('2026-09-01'));
        $before = self::period($golf, $years[2], $items, $readings);
        $after = self::period($golf, $years[3], $items, $readings);
        $change = CostChange::between($before, $after, '0');
        self::assertNotNull($change);
        $ownership = self::ownership($golf, $items, $readings, [], '2026-09-01');
        $vtc = new VehicleTrueCost(
            $golf,
            'GBP',
            $ownership,
            TrueCost::sinceBought($ownership, $items),
            null,
            null,
            [$before, $after],
            [2026 => $change],
        );

        $working = $this->builder()->change($vtc, $change);

        self::assertSame($change->perKm, $working->resultValue);
        self::assertStringContainsString(' = ', $working->steps[0]);
        $labels = array_map(static fn ($input): string => $input->label, $working->inputs);
        $row = array_search('Maintenance', $labels, true);
        self::assertIsInt($row);
        self::assertStringContainsString('→', $working->inputs[$row]->value);
        self::assertCount(1 + count($change->lines), $working->steps, 'the sum, then each cause');
        self::assertSame('The parts’ changes add up exactly to the change in the total.', $working->notes[0]);
    }

    /** #564 (Phase 54): the working of a period says how much cover it leaves out. */
    public function testThePeriodNamesCoverOutsideOwnership(): void
    {
        $golf = self::vehicle('2025-01-01', null, '2026-03-31', null);
        $before = self::document($golf, '2024-12-01', '365.000', '2025-11-30');
        $after = self::document($golf, '2026-01-01', '365.000', '2026-12-31');
        $items = [$before, $after];
        $ownership = self::ownership($golf, $items, [], [], '2026-09-01');
        $since = TrueCost::sinceBought($ownership, $items);
        $notes = [];
        foreach (TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-03-31')) as $year) {
            $cost = self::period($golf, $year, $items, []);
            $vtc = new VehicleTrueCost($golf, 'GBP', $ownership, $since, $cost, null, [], []);
            $notes[] = implode(' ', $this->builder()->period($vtc, $cost, self::date('2026-09-01'))->notes ?? []);
        }

        self::assertStringContainsString('£31.00 of cover before you bought it isn’t counted', $notes[0]);
        self::assertStringNotContainsString('after you sold it', $notes[0]);
        self::assertStringContainsString('£275.00 of cover after you sold it isn’t counted', $notes[1]);
    }

    /**
     * The Golf's year-2026 cost over a stretch of it.
     *
     * @return array{0: VehicleTrueCost, 1: TrueCost}
     */
    private function fixture(string $from, string $to): array
    {
        $golf = self::vehicle('2023-03-01', '15000.000');
        $valuations = [self::valuation('2026-01-01', '12000.000', 1), self::valuation('2026-07-01', '10000.000', 2)];
        $items = [self::maintenance($golf, '2026-03-01', '500.000')];
        $readings = [
            self::reading(1, '10000', '2023-03-01T12:00:00Z'),
            self::reading(2, '30000', '2026-01-01T12:00:00Z'),
            self::reading(3, '40000', '2026-12-31T12:00:00Z'),
        ];
        $period = new TrueCostPeriod(TrueCostRange::TwelveMonths, self::date($from), self::date($to));
        $cost = self::period($golf, $period, $items, $readings, $valuations);
        $ownership = self::ownership($golf, $items, $readings, $valuations, '2026-09-01');
        $vtc = new VehicleTrueCost(
            $golf,
            'GBP',
            $ownership,
            TrueCost::sinceBought($ownership, $items),
            $cost,
            null,
            [],
            [],
        );

        return [$vtc, $cost];
    }

    private function builder(): TrueCostWorking
    {
        $translator = WorkingWords::translator();
        $format = WorkingWords::formatter();

        return new TrueCostWorking(
            $translator,
            $format,
            WorkingWords::trueCostWording(),
            new OwnershipWorking($translator, $format),
        );
    }
}
