<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Report\PeriodDepreciation;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Report\TrueCostPeriod;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\ValueCurve;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\ValuePointKind;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *A valuation on the purchase day, two valuations on one
 * day, and a sale below or above the last valuation*; spec §8 *Money edge
 * cases*, bullet **Valuations**, with §7.1 *Depreciation* and §7.35
 * *Depreciation for a period* (decided #389, #404, #402, #154).
 *
 * Every vehicle here was bought on 1 Jan 2026 for £10,000; today is 5 Oct
 * 2026.
 */
final class ValuationsTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';
    private const string TODAY = '2026-10-05';

    public function testAValuationOnThePurchaseDayIsThatDaysClosingValueSoTheDropFallsInThePurchasePeriod(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->value($app, $car, '2026-01-01', '9000');
        $this->value($app, $car, '2026-07-01', '8500');

        $curve = $this->curve($app, $car);
        $purchaseDay = $curve->depreciation(self::day('2026-01-01'), self::day('2026-01-01'), 'GBP');
        self::assertSame('1000.000', self::loss($purchaseDay));
        self::assertSame(
            '500.000',
            self::loss($curve->depreciation(self::day('2026-01-02'), self::day('2026-06-30'), 'GBP')),
            '9,000 on 2 Jan to 8,500 on 1 Jul',
        );

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertSame('1500.000', $result->ownership->depreciationCost?->toDecimal(3), 'since bought: 10,000 to 8,500');
        self::assertSame('1500.000', $result->years[0]->depreciation?->toDecimal(3), 'the periods add up to it');
    }

    public function testASaleOnThePurchaseDayIsThatDaysClosingValueToo(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000', sold: '2026-01-01', soldFor: '9000');

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertSame(ValuePointKind::Sold, $result->ownership->depreciation->current?->kind);
        self::assertSame('1000.000', $result->ownership->depreciationCost?->toDecimal(3));
        self::assertCount(1, $result->years);
        self::assertSame('1000.000', $result->years[0]->depreciation?->toDecimal(3), 'the drop falls in the purchase period');
        $purchaseDay = $this->curve($app, $car)->depreciation(self::day('2026-01-01'), self::day('2026-01-01'), 'GBP');
        self::assertSame('1000.000', self::loss($purchaseDay));
    }

    public function testTwoValuationsOnOneDayTheLastAddedWinsForTheCurrentValueAndTheCurve(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $lower = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->value($app, $lower, '2026-06-01', '8000');
        $this->value($app, $lower, '2026-06-01', '7500');
        $higher = $this->car($app, 'Polo', '2026-01-01', '10000');
        $this->value($app, $higher, '2026-06-01', '7500');
        $this->value($app, $higher, '2026-06-01', '8000');

        foreach ([[$lower, '7500', '2500.000'], [$higher, '8000', '2000.000']] as [$car, $last, $lost]) {
            $result = $this->trueCost($app, $owner, $car, self::TODAY);
            self::assertDecimal($last, $result->ownership->depreciation->current?->amount, 'current value: the last added');
            self::assertSame($lost, $result->ownership->depreciationCost?->toDecimal(3));
            $curve = $this->curve($app, $car);
            self::assertSame(
                $lost,
                self::loss($curve->depreciation(self::day('2026-01-01'), self::day('2026-05-31'), 'GBP')),
                'the curve passes through the last added value on 1 Jun',
            );
            self::assertSame($lost, $result->years[0]->depreciation?->toDecimal(3), 'and so does the year');
        }
    }

    public function testASaleBelowTheLastValuationIsTheValueWhateverTheValuation(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000', sold: '2026-06-01', soldFor: '8000');
        $this->value($app, $car, '2026-03-01', '9000');

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertSame(ValuePointKind::Sold, $result->ownership->depreciation->current?->kind);
        self::assertDecimal('8000', $result->ownership->depreciation->current?->amount);
        self::assertSame('2000.000', $result->ownership->depreciationCost?->toDecimal(3), '10,000 to the sale, not the 9,000');

        $curve = $this->curve($app, $car);
        self::assertSame(
            '1000.000',
            self::loss($curve->depreciation(self::day('2026-03-01'), self::day('2026-05-31'), 'GBP')),
            '9,000 on 1 Mar to the sale price on 1 Jun',
        );
    }

    public function testASaleAboveTheLastValuationButBelowThePriceIsANegativeDepreciationPartInThatPeriod(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000', sold: '2026-06-01', soldFor: '9000');
        $this->value($app, $car, '2026-03-01', '8000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '2000', '2026-02-15T12:00:00Z');
        $this->reading($app, $car, '3500', '2026-05-31T12:00:00Z');
        $this->expense($app, $car, '2026-03-10', '50.00');

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertSame('1000.000', $result->ownership->depreciationCost?->toDecimal(3), 'overall a loss on the price');

        // 1 Mar to 31 May: the car was valued at 8,000 and sold for 9,000.
        $period = $this->periodOf($app, $owner, $car, '2026-03-01', '2026-05-31');
        self::assertSame('-1000.000', $period->depreciation?->toDecimal(3), 'a gain in that period: negative');
        self::assertDecimal('-0.666667', $period->rate(TruePart::Depreciation), 'a negative part per km: -1,000 over 1,500 km');
        self::assertNotNull($period->perKm);
        self::assertSame(-1, Decimal::compare($period->perKm, '0'), 'and the period costs less than nothing per km');
    }

    public function testASaleAboveThePriceIsAGainWithANegativePerDistancePartAndNoPerYearFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000', sold: '2026-06-01', soldFor: '12000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '4000', '2026-06-01T12:00:00Z');
        $this->expense($app, $car, '2026-02-01', '100.00');

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        $depreciation = $result->ownership->depreciation;
        self::assertTrue($depreciation->isGain());
        self::assertDecimal('2000', $depreciation->change, 'up £2,000');
        self::assertNull($depreciation->perYear, 'no per-year figure for a gain');
        self::assertDecimal('-0.666667', $depreciation->perKm, 'a negative per-distance part: -2,000 over 3,000 km');
        self::assertSame('-2000.000', $result->ownership->depreciationCost?->toDecimal(3), 'the gain is money back');
        self::assertNull($result->ownership->depreciationPerMonth, 'and no per-month part');
        self::assertSame('-1900.000', $result->ownership->total?->toDecimal(3), 'running £100 less the £2,000 gain');
        self::assertDecimal('-0.666667', $result->sinceBought->rate(TruePart::Depreciation), 'true cost shows it negative');
        self::assertDecimal('-0.633334', $result->sinceBought->perKm, 'running 0.033333 + depreciation -0.666667');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function curve(App $app, Vehicle $car): ValueCurve
    {
        return ValueCurve::of($car, $this->service($app, ValuationService::class)->forVehicle($car));
    }

    /**
     * True cost over a custom stretch of days, as the year periods are worked out.
     *
     * @param App<ContainerInterface> $app
     */
    private function periodOf(App $app, User $owner, Vehicle $car, string $from, string $to): TrueCost
    {
        return TrueCost::forPeriod(
            $car,
            'GBP',
            new TrueCostPeriod(TrueCostRange::Year, self::day($from), self::day($to), 2026),
            $this->service($app, CostLedger::class)->items($owner, [$car]),
            $this->service($app, OdometerReadingRepository::class)->listForVehicle($car->id),
            $this->curve($app, $car),
            [],
            $owner->preferences->timeZone(),
        );
    }

    private static function loss(?PeriodDepreciation $depreciation): string
    {
        self::assertNotNull($depreciation, 'a period with a value at both ends');

        return $depreciation->amount->toDecimal(3);
    }
}
