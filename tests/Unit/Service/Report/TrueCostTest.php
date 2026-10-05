<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Service\Report\InsurancePayout;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostGap;
use Logbook\Service\Report\TrueCostPeriod;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\TruePart;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * True cost per period (docs/phases/phase-32.md *Breakdown* and *Periods*):
 * the parts add up exactly, documents are spread over their cover, and
 * years follow the owner's calendar.
 */
final class TrueCostTest extends TestCase
{
    use TrueCostFixtures;

    public function testSinceBoughtSplitsCostOfOwnershipExactly(): void
    {
        // Odd amounts over an odd distance, so every part's rate has a remainder.
        $golf = self::vehicle('2023-03-01', '15000.000');
        $items = [
            self::fill($golf, '2023-06-01T12:00:00Z', '7001.117'),
            self::maintenance($golf, '2024-05-10', '2999.993'),
            self::maintenance($golf, '2024-11-02', '412.380', MaintenanceCategory::Tyres),
            self::document($golf, '2025-03-01', '1700.001', '2026-02-28'),
            self::expense($golf, '2025-04-01', '333.333', ExpenseCategory::Finance),
            self::expense($golf, '2025-05-01', '17.770'),
        ];
        $readings = [
            self::reading(1, '10000', '2023-03-01T12:00:00Z'),
            self::reading(2, '67936.384', '2026-03-01T12:00:00Z'),
            self::reading(3, '72764.417', '2026-09-01T12:00:00Z'),
        ];
        $payouts = [new InsurancePayout(self::date('2024-06-01'), Money::of('400.004', 'GBP'), 5)];

        $valuations = [self::valuation('2026-03-01', '9800.000')];
        $ownership = self::ownership($golf, $items, $readings, $valuations, '2026-09-01', $payouts);
        $true = TrueCost::sinceBought($ownership, $items);

        self::assertNotNull($ownership->perKm);
        $sum = $true->payoutsPerKm ?? '0';
        foreach (TruePart::cases() as $part) {
            $sum = Decimal::add($sum, $true->rate($part) ?? '0');
        }
        self::assertSame($ownership->perKm, $sum, 'five parts and the payouts add up to Phase 14.2\'s figure');
        self::assertSame($ownership->depreciationPerKm, $true->rate(TruePart::Depreciation));
        self::assertSame('3412.373', $true->amount(TruePart::Maintenance)?->toDecimal(3), 'tyres are maintenance');
        self::assertSame('351.103', $true->amount(TruePart::Other)?->toDecimal(3), 'finance lines are other');
        self::assertSame('1700.001', $true->amount(TruePart::Documents)?->toDecimal(3), 'documents on their ledger date');
        self::assertNotNull($true->payoutsPerKm);
        self::assertTrue(Decimal::compare($true->payoutsPerKm, '0') < 0, 'payouts are money back');
        self::assertSame('5200.000', $true->depreciation?->toDecimal(3));
        self::assertSame('2026-03-01', $true->depreciationTo?->format('Y-m-d'));
        self::assertFalse($true->isRunningOnly());
        self::assertSame($ownership->total?->toDecimal(6), $true->total()->toDecimal(6));
    }

    public function testAYearsPartsAddUpToItsTotal(): void
    {
        $golf = self::vehicle('2023-03-01', '15000.000');
        $items = [
            self::fill($golf, '2024-02-01T12:00:00Z', '61.27', '41.11'),
            self::fill($golf, '2024-08-01T12:00:00Z', '58.91', '39.87'),
            self::maintenance($golf, '2024-05-10', '249.99'),
            self::expense($golf, '2024-07-01', '12.40'),
        ];
        $readings = [self::reading(1, '10000', '2023-03-01T12:00:00Z'), self::reading(2, '23011.7', '2024-12-31T12:00:00Z')];

        [, $year] = TrueCostPeriod::years(self::date('2023-03-01'), self::date('2026-09-01'));
        $true = self::period($golf, $year, $items, $readings, [self::valuation('2026-03-01', '9800.000')]);

        self::assertSame(2024, $true->period->year);
        self::assertFalse($true->period->isPartial());
        self::assertNotNull($true->perKm);
        $sum = '0';
        foreach ($true->parts() as $part) {
            $sum = Decimal::add($sum, $true->rate($part) ?? '0');
        }
        self::assertSame($true->perKm, $sum);
        self::assertNotNull($true->rate(TruePart::Depreciation), 'the year lies between the purchase and the valuation');
        self::assertNull($true->depreciationTo, 'not cut');
        self::assertSame($true->distanceKm, $true->depreciationKm);
    }

    public function testADocumentIsSpreadOverItsCoverInYearsButNotSinceBought(): void
    {
        // £365 of insurance from 1 Jul 2025 to 30 Jun 2026: £1 a day.
        $car = self::vehicle('2025-01-01', '10000.000');
        $policy = self::document($car, '2025-07-01', '365.000', '2026-06-30');
        $readings = [self::reading(1, '1000', '2025-01-01T12:00:00Z'), self::reading(2, '21000', '2026-09-01T12:00:00Z')];

        [$y2025, $y2026] = TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01'));
        $first = self::period($car, $y2025, [$policy], $readings);
        $second = self::period($car, $y2026, [$policy], $readings);
        $since = TrueCost::sinceBought(self::ownership($car, [$policy], $readings, [], '2026-09-01'), [$policy]);

        self::assertSame('184.000', $first->amount(TruePart::Documents)?->toDecimal(3), 'July to December');
        self::assertSame('181.000', $second->amount(TruePart::Documents)?->toDecimal(3), 'January to June');
        self::assertSame('365.000', $since->amount(TruePart::Documents)?->toDecimal(3), 'on its date, as Phase 14.2');
    }

    public function testDocumentSharesAlwaysAddUpToTheCost(): void
    {
        // £100 over 7 days: shares of 14.285714… a day.
        $car = self::vehicle('2025-01-01', '10000.000');
        $policy = self::document($car, '2025-12-29', '100.000', '2026-01-04');

        $sum = Money::zero('GBP');
        foreach (TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01')) as $year) {
            $sum = $sum->add(self::period($car, $year, [$policy], [])->amount(TruePart::Documents) ?? Money::zero('GBP'));
        }

        self::assertSame('100.000000', $sum->toDecimal(6));
    }

    public function testADocumentWithoutAnExpiryCountsOnItsDate(): void
    {
        $car = self::vehicle('2025-01-01', '10000.000');
        $tax = self::document($car, '2025-12-01', '190.000');

        [$y2025] = TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01'));

        self::assertSame('190.000', self::period($car, $y2025, [$tax], [])->amount(TruePart::Documents)?->toDecimal(3));
    }

    public function testYearsFollowTheOwnersCalendar(): void
    {
        $car = self::vehicle('2025-01-01', '10000.000');
        $late = self::fill($car, '2025-12-31T23:30:00Z', '50.000');
        $early = self::fill($car, '2026-01-01T00:30:00Z', '60.000');

        [$y2025, $y2026] = TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01'));

        $first = self::period($car, $y2025, [$late, $early], []);
        $second = self::period($car, $y2026, [$late, $early], []);

        self::assertSame('50.000', $first->amount(TruePart::Fuel)?->toDecimal(3), '23:30 on 31 Dec in London');
        self::assertSame('60.000', $second->amount(TruePart::Fuel)?->toDecimal(3));
    }

    public function testPartialYearsSaySo(): void
    {
        $years = TrueCostPeriod::years(self::date('2023-03-14'), self::date('2026-10-05'));

        self::assertCount(4, $years);
        self::assertTrue($years[0]->partialStart, '"2023 from 14 Mar"');
        self::assertFalse($years[0]->partialEnd);
        self::assertFalse($years[1]->isPartial());
        self::assertTrue($years[3]->partialEnd, '"2026 so far"');
        self::assertSame('2026-10-05', $years[3]->to->format('Y-m-d'));
    }

    public function testTwelveMonthsAreTheReportsPresetCutToOwnership(): void
    {
        $last = TrueCostPeriod::twelveMonths(self::date('2026-10-05'), self::date('2026-01-20'), self::date('2026-10-05'));
        $today = self::date('2026-10-05');
        $before = TrueCostPeriod::twelveMonths($today, self::date('2024-01-01'), $today, true);

        self::assertNotNull($last);
        self::assertSame('2026-01-20', $last->from->format('Y-m-d'), 'bought part-way: from the purchase');
        self::assertTrue($last->partialStart);
        self::assertNotNull($before);
        self::assertSame(TrueCostRange::PreviousTwelveMonths, $before->range);
        self::assertSame('2024-11-01', $before->from->format('Y-m-d'));
        self::assertSame('2025-10-31', $before->to->format('Y-m-d'));
        self::assertNull(
            TrueCostPeriod::twelveMonths(self::date('2026-10-05'), self::date('2026-01-20'), self::date('2026-10-05'), true),
            'not owned then',
        );
    }

    public function testUnder500KmIsNotComparable(): void
    {
        $car = self::vehicle('2025-01-01', '10000.000');
        $items = [self::fill($car, '2025-06-01T12:00:00Z', '50.000')];

        [$y2025] = TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01'));
        $start = self::reading(1, '1000', '2025-01-01T12:00:00Z');
        $short = self::period($car, $y2025, $items, [$start, self::reading(2, '1499.9', '2025-12-01T12:00:00Z')]);
        $enough = self::period($car, $y2025, $items, [$start, self::reading(2, '1500', '2025-12-01T12:00:00Z')]);

        self::assertNotNull($short->perKm, 'still in the table');
        self::assertFalse($short->isComparable());
        self::assertTrue($enough->isComparable());
    }

    public function testWithoutMileageBackToTheStartThereIsNoRate(): void
    {
        $car = self::vehicle('2025-01-01', '10000.000');

        [$y2025] = TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01'));
        $true = self::period(
            $car,
            $y2025,
            [self::fill($car, '2025-06-01T12:00:00Z', '50.000')],
            [self::reading(1, '1000', '2025-03-01T12:00:00Z'), self::reading(2, '9000', '2025-12-01T12:00:00Z')],
        );

        self::assertNull($true->perKm);
        self::assertSame(TrueCostGap::NoMileage, $true->gap);
    }

    public function testNothingLoggedIsNotNothingSpent(): void
    {
        $car = self::vehicle('2025-01-01', '10000.000');

        [$y2025] = TrueCostPeriod::years(self::date('2025-01-01'), self::date('2026-09-01'));
        $readings = [self::reading(1, '1000', '2025-01-01T12:00:00Z'), self::reading(2, '9000', '2025-12-01T12:00:00Z')];
        $true = self::period($car, $y2025, [], $readings);

        self::assertNull($true->perKm);
        self::assertSame(TrueCostGap::NoCosts, $true->gap);
    }

    public function testAPeriodWhollyAfterTheLatestValueIsRunningCostsOnly(): void
    {
        $car = self::vehicle('2023-01-01', '15000.000');
        $readings = [self::reading(1, '1000', '2023-01-01T12:00:00Z'), self::reading(2, '40000', '2026-09-01T12:00:00Z')];

        $year = TrueCostPeriod::years(self::date('2023-01-01'), self::date('2026-09-01'))[3];
        $fill = self::fill($car, '2026-04-01T12:00:00Z', '60.000');
        $true = self::period($car, $year, [$fill], $readings, [self::valuation('2025-12-01', '9000.000')]);

        self::assertNull($true->depreciation, 'shown as "—"');
        self::assertTrue($true->isRunningOnly());
    }

    public function testTheBarDrawsOnlyPositiveParts(): void
    {
        $classic = self::vehicle('2020-01-01', '12000.000');
        $readings = [self::reading(1, '1000', '2020-01-01T12:00:00Z'), self::reading(2, '21000', '2025-12-31T12:00:00Z')];
        $items = [
            self::fill($classic, '2025-06-01T12:00:00Z', '1000.000'),
            self::maintenance($classic, '2025-07-01', '1000.000'),
        ];

        $true = self::period(
            $classic,
            TrueCostPeriod::years(self::date('2020-01-01'), self::date('2026-01-01'))[5],
            $items,
            $readings,
            [self::valuation('2026-01-01', '18000.000')],
        );

        self::assertTrue(Decimal::compare($true->rate(TruePart::Depreciation) ?? '0', '0') < 0, 'a gain');
        self::assertSame([TruePart::Fuel, TruePart::Maintenance], array_column($true->bar(), 'part'));
        self::assertSame([50.0, 50.0], array_column($true->bar(), 'percent'));
    }
}
