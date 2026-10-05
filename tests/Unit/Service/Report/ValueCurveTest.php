<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use Logbook\Service\Report\ValueCurve;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Depreciation for a period (docs/phases/phase-32.md *Value curve*):
 * straight lines between value points, never extrapolated.
 */
final class ValueCurveTest extends TestCase
{
    use TrueCostFixtures;

    public function testTheValueBetweenTwoPointsIsAStraightLineByDay(): void
    {
        // £15,000 on 1 Mar 2023, £12,000 on 1 Mar 2024: 366 days (a leap year).
        $curve = ValueCurve::of(self::vehicle('2023-03-01', '15000.000'), [self::valuation('2024-03-01', '12000.000')]);

        self::assertSame('15000.000', self::value($curve, self::date('2023-03-01')));
        self::assertSame('13500.000', self::value($curve, self::date('2023-08-31')), '183 of 366 days: half way');
        self::assertSame('12000.000', self::value($curve, self::date('2024-03-01')));
        self::assertNull($curve->valueOn(self::date('2023-02-28')), 'nothing before the purchase');
        self::assertNull($curve->valueOn(self::date('2024-03-02')), 'nothing after the latest point');
    }

    public function testAPeriodBetweenTwoValuationsIsInterpolated(): void
    {
        $curve = ValueCurve::of(self::vehicle('2023-03-01', '15000.000'), [self::valuation('2024-03-01', '12000.000')]);

        // 1 Jun to 30 Nov 2023: from day 92 to day 275 of 366, so 183 days of £3,000.
        $loss = $curve->depreciation(self::date('2023-06-01'), self::date('2023-11-30'), 'GBP');

        self::assertNotNull($loss);
        self::assertSame('1500.000', $loss->amount->toDecimal(3));
        self::assertFalse($loss->cutAtStart);
        self::assertFalse($loss->cutAtEnd);
    }

    public function testAdjacentYearsAddUpToTheWholeLoss(): void
    {
        $golf = self::vehicle('2023-03-01', '15000.000');
        $valuations = [self::valuation('2024-03-01', '12000.000'), self::valuation('2026-03-01', '9800.000', 2)];
        $curve = ValueCurve::of($golf, $valuations);

        $sum = '0';
        foreach (['2023', '2024', '2025', '2026'] as $year) {
            $loss = $curve->depreciation(self::date($year . '-01-01'), self::date($year . '-12-31'), 'GBP');
            self::assertNotNull($loss);
            $sum = Decimal::add($sum, $loss->amount->toDecimal(6));
        }

        self::assertEqualsWithDelta(5200.0, (float) $sum, 0.000002, 'no day lost between 31 Dec and 1 Jan');
    }

    public function testAPeriodPastTheLatestPointStopsThereAndSaysSo(): void
    {
        $curve = ValueCurve::of(self::vehicle('2023-03-01', '15000.000'), [self::valuation('2026-03-01', '9800.000')]);

        $loss = $curve->depreciation(self::date('2026-01-01'), self::date('2026-12-31'), 'GBP');

        self::assertNotNull($loss);
        self::assertTrue($loss->cutAtEnd, '"depreciation to 1 Mar 2026"');
        self::assertSame('2026-03-01', $loss->to->format('Y-m-d'));
        // 59 of 1,096 days of £5,200.
        self::assertSame('279.927', $loss->amount->toDecimal(3));
    }

    public function testAPeriodWhollyAfterTheLatestPointHasNone(): void
    {
        $curve = ValueCurve::of(self::vehicle('2023-03-01', '15000.000'), [self::valuation('2026-03-01', '9800.000')]);

        self::assertNull($curve->depreciation(self::date('2026-03-02'), self::date('2026-12-31'), 'GBP'));
        $onThePoint = $curve->depreciation(self::date('2026-03-01'), self::date('2026-12-31'), 'GBP');
        self::assertNull($onThePoint, 'one day on the point measures nothing after it');
        $toTheEve = $curve->depreciation(self::date('2026-01-01'), self::date('2026-02-28'), 'GBP');
        self::assertNotNull($toTheEve, 'up to the eve of the point: not cut');
    }

    public function testAPeriodStartingBeforeThePurchaseStartsAtIt(): void
    {
        $curve = ValueCurve::of(self::vehicle('2023-03-01', '15000.000'), [self::valuation('2024-03-01', '12000.000')]);

        $loss = $curve->depreciation(self::date('2023-01-01'), self::date('2023-12-31'), 'GBP');

        self::assertNotNull($loss);
        self::assertTrue($loss->cutAtStart);
        self::assertSame('2023-03-01', $loss->from->format('Y-m-d'));
    }

    public function testASoldVehicleIsExactToTheSaleDate(): void
    {
        $sold = self::vehicle('2023-03-01', '15000.000', '2025-03-14', '9000.000');

        $loss = ValueCurve::of($sold, [self::valuation('2024-03-01', '12000.000')])
            ->depreciation(self::date('2023-03-01'), self::date('2026-10-05'), 'GBP');

        self::assertNotNull($loss);
        self::assertSame('6000.000', $loss->amount->toDecimal(3));
        self::assertSame('2025-03-14', $loss->to->format('Y-m-d'));
    }

    public function testALeaseWithoutAPurchasePriceHasNoDepreciation(): void
    {
        $lease = self::vehicle('2024-01-01', null);

        $valuations = [self::valuation('2024-06-01', '20000.000'), self::valuation('2025-06-01', '17000.000', 2)];
        $curve = ValueCurve::of($lease, $valuations);

        self::assertNull($curve->first());
        self::assertNull($curve->depreciation(self::date('2024-01-01'), self::date('2025-12-31'), 'GBP'));
    }

    public function testAGainIsNegative(): void
    {
        $classic = self::vehicle('2020-03-01', '12000.000');

        $loss = ValueCurve::of($classic, [self::valuation('2026-03-01', '13100.000')])
            ->depreciation(self::date('2020-03-01'), self::date('2026-03-01'), 'GBP');

        self::assertNotNull($loss);
        self::assertSame('-1100.000', $loss->amount->toDecimal(3));
    }

    public function testTheLastPointOfADayCounts(): void
    {
        $car = self::vehicle('2023-03-01', '15000.000');

        $valuations = [self::valuation('2024-03-01', '12500.000'), self::valuation('2024-03-01', '12000.000', 2)];
        $curve = ValueCurve::of($car, $valuations);

        self::assertCount(2, $curve->points);
        self::assertSame('12000.000', self::value($curve, self::date('2024-03-01')));
    }

    private static function value(ValueCurve $curve, DateTimeImmutable $day): ?string
    {
        return $curve->valueOn($day)?->toScale(3)->toString();
    }
}
