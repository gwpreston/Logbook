<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Finance\FinanceInput;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Report\ShownOwnership;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReport;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\MileageRateService;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *Rounding at half a penny in each currency's minor unit*;
 * spec §8 *Money edge cases*, bullet **Rounding**, with §7.23 *Valuing
 * trips*, §7.32 *Figures* and *Costs* (decided #390, #403).
 *
 * Amounts are summed exactly and rounded once, at display or export, half
 * away from zero, to the currency's minor unit (0 for JPY, 2 for GBP and
 * EUR, 3 for BHD). Claim lines and finance amounts are rounded per line.
 * The cases are chosen so half-up, half-even, half-towards-plus-infinity
 * and rounding each line first all give different answers.
 *
 * Shown amounts are compared without their currency symbol, which ICU
 * spells to the locale (en_GB writes yen as "JP¥").
 */
final class RoundingTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> currency, exact amount, shown to the minor unit
     */
    public static function halves(): array
    {
        return [
            'GBP half a penny' => ['GBP', '1.005', '1.01'],
            'GBP 0.125, where half-even gives 0.12' => ['GBP', '0.125', '0.13'],
            'GBP 2.675, a float below the half' => ['GBP', '2.675', '2.68'],
            'GBP negative half a penny' => ['GBP', '-1.005', '-1.01'],
            'GBP negative 0.125' => ['GBP', '-0.125', '-0.13'],
            'EUR half a cent' => ['EUR', '1.005', '1.01'],
            'EUR negative half a cent' => ['EUR', '-1.005', '-1.01'],
            'JPY half a yen' => ['JPY', '0.5', '1'],
            'JPY 2.5, where half-even gives 2' => ['JPY', '2.5', '3'],
            'JPY negative half a yen' => ['JPY', '-0.5', '-1'],
            'JPY negative 2.5' => ['JPY', '-2.5', '-3'],
            'BHD half a fils' => ['BHD', '1.0005', '1.001'],
            'BHD 1.0025, where half-even gives 1.002' => ['BHD', '1.0025', '1.003'],
            'BHD negative 1.0025' => ['BHD', '-1.0025', '-1.003'],
        ];
    }

    #[DataProvider('halves')]
    public function testHalfAMinorUnitRoundsAwayFromZeroInEveryCurrencyAtDisplayAndExport(
        string $currency,
        string $amount,
        string $expected,
    ): void {
        [$app, $owner] = $this->world(self::NOW);
        $money = Money::of($amount, $currency);

        $shown = $this->shown($app, $owner)->money($money, $currency);
        $digits = preg_replace('/[^0-9.\-]/u', '', str_replace("\u{2212}", '-', $shown));
        self::assertSame($expected, $digits, "{$amount} {$currency} shown as {$shown}");
        self::assertSame($expected, $money->toDecimal(Currency::fractionDigits($currency)), 'and exported');
    }

    public function testGbpHalfAPennyShowsWithItsSymbolAndSign(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $fmt = $this->shown($app, $owner);

        self::assertSame('£0.13', $fmt->money(Money::of('0.125', 'GBP')));
        self::assertSame('−£0.13', $fmt->money(Money::of('-0.125', 'GBP')));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string, 3: string}> currency, entered amounts,
     *     the exact total, the total shown
     */
    public static function sums(): array
    {
        return [
            'three £0.005 are £0.015: £0.02, not £0.03' => ['GBP', ['0.005', '0.005', '0.005'], '0.015', '0.02'],
            'two ¥0.5 are ¥1, not ¥2' => ['JPY', ['0.5', '0.5'], '1', '1'],
            'three €0.004 are €0.012: €0.01, not €0.00' => ['EUR', ['0.004', '0.004', '0.004'], '0.012', '0.01'],
        ];
    }

    /**
     * @param list<string> $entered
     */
    #[DataProvider('sums')]
    public function testAmountsAreSummedExactlyAndRoundedOnceNotLineByLine(
        string $currency,
        array $entered,
        string $exact,
        string $expected,
    ): void {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', null, $currency);
        foreach ($entered as $amount) {
            $this->expense($app, $car, '2026-06-01', $amount);
        }

        $report = $this->report($app, $owner, $car, '2026-06-01', '2026-06-30');
        self::assertSame(count($entered), $report->count);
        self::assertDecimal($exact, $report->total->toDecimal(6), 'the sum is exact underneath');
        $shown = $this->shown($app, $owner)->money($report->shown->total, $currency);
        $digits = preg_replace('/[^0-9.\-]/u', '', str_replace("\u{2212}", '-', $shown));
        self::assertSame($expected, $digits, "shown as {$shown}");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> currency, the one expense, the
     *     average over two months exactly, shown
     */
    public static function averages(): array
    {
        return [
            'GBP 0.25 over two months is 0.125' => ['GBP', '0.25', '0.125', '0.13'],
            'EUR 2.01 over two months is 1.005' => ['EUR', '2.01', '1.005', '1.01'],
            'JPY 5 over two months is 2.5' => ['JPY', '5', '2.5', '3'],
            'BHD 3.005 over two months is 1.5025' => ['BHD', '3.005', '1.5025', '1.503'],
        ];
    }

    #[DataProvider('averages')]
    public function testAnAveragePerMonthAtHalfAMinorUnitIsRoundedHalfAwayFromZeroForDisplay(
        string $currency,
        string $spent,
        string $average,
        string $expected,
    ): void {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', null, $currency);
        $this->expense($app, $car, '2026-08-10', $spent);

        $report = $this->report($app, $owner, $car, '2026-08-01', '2026-09-30');
        self::assertDecimal($average, $report->averagePerMonth->toDecimal(6), 'exactly, over the two months');
        $shown = $this->shown($app, $owner)->money($report->averagePerMonth, $currency);
        $digits = preg_replace('/[^0-9.\-]/u', '', str_replace("\u{2212}", '-', $shown));
        self::assertSame($expected, $digits, "shown as {$shown}");
    }

    public function testANegativeHalfPennyIsRoundedAwayFromZeroNotUp(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', null);
        $this->expense($app, $car, '2026-03-01', '0.875');
        $incidents = $this->service($app, IncidentService::class);
        $incidents->create($car, new IncidentData(
            self::day('2026-05-01'),
            IncidentType::Collision,
            status: IncidentStatus::Closed,
            closedOn: self::day('2026-05-01'),
            claim: new Claim(ClaimStatus::Settled, 'Insurer', payout: '1.00'),
        ), null, $owner->preferences->timeZone());

        $ownership = $this->trueCost($app, $owner, $car, '2026-10-05')->ownership;
        self::assertSame('-0.125', $ownership->running->toDecimal(3), '£0.875 of costs less a £1.00 payout');
        $shown = ShownOwnership::of($ownership)->running;
        self::assertSame('−£0.13', $this->shown($app, $owner)->money($shown), 'away from zero: −£0.13, not −£0.12');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> currency, rate for the
     *     first km, rate after it, each line, the trip
     */
    public static function claims(): array
    {
        return [
            'GBP 0.5p twice' => ['GBP', '0.0050', '0.0025', '0.01', '0.02'],
            'JPY half a yen twice' => ['JPY', '0.5000', '0.2500', '1', '2'],
            'BHD half a fils, then 0.6 fils' => ['BHD', '0.0005', '0.0003', '0.001', '0.002'],
        ];
    }

    #[DataProvider('claims')]
    public function testAClaimIsRoundedOnEveryLineSoASplitTripsLinesAddUpToItsAmount(
        string $currency,
        string $first,
        string $after,
        string $line,
        string $trip,
    ): void {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2020-01-01', null);
        // The first kilometre of the tax year at one rate, the rest at another.
        $this->service($app, MileageRateService::class)->create($owner, new MileageRateSetData(
            effectiveFrom: self::day('2026-04-06'),
            distanceUnit: DistanceUnit::Kilometre,
            currency: $currency,
            carRate: $first,
            carThreshold: '1.000',
            carRateAfter: $after,
        ));
        // 3 km: 1 km at the first rate and 2 km at the second.
        $this->service($app, TripService::class)->create($car, new TripData(
            self::day('2026-06-01'),
            'Office',
            'Client',
            distanceKm: '3.000',
            isBusiness: true,
            purpose: 'Visit',
        ));

        $claims = $this->service($app, ClaimReportService::class);
        $report = $claims->build($owner, ClaimFilter::taxYear($claims->taxYearOf($owner, self::day('2026-06-01'))));

        self::assertCount(1, $report->rows);
        $row = $report->rows[0];
        self::assertCount(2, $row->lines, 'a trip that crosses the threshold has two lines');
        self::assertDecimal($line, $row->lines[0]->amount, 'the first line, rounded');
        self::assertDecimal($line, $row->lines[1]->amount, 'the second line, rounded');
        self::assertDecimal($trip, $row->mileageAmount, 'the trip is the sum of its rounded lines');
        self::assertDecimal($trip, $report->totals[0]->mileageAmount, 'and the total of the trips');
        self::assertSame($currency, $report->totals[0]->currency);
    }

    public function testAClaimOfTwoTripsIsTheSumOfEachTripsRoundedAmount(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2020-01-01', null);
        $this->service($app, MileageRateService::class)->create($owner, new MileageRateSetData(
            effectiveFrom: self::day('2026-04-06'),
            distanceUnit: DistanceUnit::Kilometre,
            currency: 'BHD',
            carRate: '0.0125',
        ));
        foreach (['2026-06-01', '2026-06-02'] as $day) {
            $this->service($app, TripService::class)->create($car, new TripData(
                self::day($day),
                'Office',
                'Client',
                distanceKm: '1.000',
                isBusiness: true,
                purpose: 'Visit',
            ));
        }

        $report = $this->claim($app, $owner, '2026-06-01');
        foreach ($report->rows as $row) {
            self::assertDecimal('0.013', $row->mileageAmount, 'a trip');
        }
        self::assertDecimal('0.026', $report->totals[0]->mileageAmount, 'BHD 0.0125 a trip: 0.013 each, 0.026 not 0.025');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> currency, credit, the share
     *     exactly, the share as a line, the regular payment
     */
    public static function shares(): array
    {
        return [
            'GBP: 32.545 is 32.55 (half-even gives 32.54)' => ['GBP', '2000', '32.545', '32.55', '190'],
            'JPY: 3254.5 is 3255 (half-even gives 3254)' => ['JPY', '200000', '3254.5', '3255', '20000'],
            'BHD: 3.2545 is 3.255 (half-even gives 3.254)' => ['BHD', '200', '3.2545', '3.255', '20'],
        ];
    }

    /**
     * A first payment's interest share is the amount of credit times the
     * monthly rate for the month since the agreement. At 21.373% APR the
     * monthly rate is exactly 0.0162725000, so a credit of 2,000 earns
     * 32.545: half a penny. (Fees and settlements are typed to the minor unit,
     * so the shares are the only finance amounts that can fall on a half.)
     */
    #[DataProvider('shares')]
    public function testAFinanceInterestShareAtExactlyHalfAMinorUnitIsRoundedAwayFromZero(
        string $currency,
        string $credit,
        string $exact,
        string $expected,
        string $payment,
    ): void {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-15', $credit, $currency);
        $finance = $this->service($app, FinanceService::class);
        $id = $finance->create($owner, $car, new FinanceInput(new AgreementData(
            type: AgreementType::Hp,
            lender: 'Lender',
            agreementNumber: null,
            startedOn: self::day('2026-01-15'),
            firstPaymentOn: self::day('2026-02-15'),
            numberOfPayments: 12,
            regularPayment: $payment,
            cashPrice: $credit,
            apr: '21.373',
        )));

        $view = $finance->view($owner, $car, $finance->get($owner, $car, $id));
        self::assertDecimal($expected, $view->figures->interest[1], "{$exact} rounded to the minor unit");
        $lines = $finance->costLines($owner, [$car])[$car->id] ?? [];
        self::assertDecimal($expected, $lines[0]['line']->amount, 'and the cost line is that share');
        $items = $this->service($app, CostLedger::class)->items($owner, [$car]);
        self::assertSame($expected, $items[0]->amount->toDecimal(Currency::fractionDigits($currency)), 'in the ledger');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function claim(App $app, User $owner, string $dayInYear): ClaimReport
    {
        $claims = $this->service($app, ClaimReportService::class);

        return $claims->build($owner, ClaimFilter::taxYear($claims->taxYearOf($owner, self::day($dayInYear))));
    }
}
