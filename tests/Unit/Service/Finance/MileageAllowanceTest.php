<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Finance\MileageAllowance;
use Logbook\Service\Finance\MileagePosition;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Support\Units\DistanceUnit;
use PHPUnit\Framework\TestCase;

/**
 * Mileage against the allowance (spec.md §7.32 *Mileage*), worked by hand:
 * a PCP from 1 Jan 2025 with 35 payments from 1 Feb 2025 and the final
 * payment on 1 Jan 2028 runs 36 months, so 8,000 mi a year allows 24,000 mi.
 */
final class MileageAllowanceTest extends TestCase
{
    private const float MI = DistanceUnit::KM_PER_MILE;

    public function testTheAllowanceRunsFromTheAgreementDateToTheEnd(): void
    {
        $position = $this->position($this->pcp(), [], '2026-07-02');

        self::assertSame(36, $position->months);
        self::assertSame('2028-01-01', $position->endsOn->format('Y-m-d'));
        self::assertEqualsWithDelta(24000.0, $this->miles($position->allowanceKm), 0.01);
        // 547 of 1,095 days.
        self::assertEqualsWithDelta(24000 * 547 / 1095, $this->miles($position->allowedToDateKm), 0.01);
    }

    public function testALeaseRunsAMonthPastItsLastRental(): void
    {
        // An initial rental on 10 Mar 2025, then 35 rentals from 10 Apr 2025: 36 months.
        $lease = FinanceFixtures::agreement([
            'type' => AgreementType::Lease,
            'startedOn' => '2025-03-10',
            'firstPaymentOn' => '2025-04-10',
            'numberOfPayments' => 35,
            'regularPayment' => '300',
            'initialRental' => '1800',
            'annualMileageAllowance' => 10000,
            'mileageUnit' => DistanceUnit::Kilometre,
            'excessMileageCharge' => '0.05',
        ]);
        $position = $this->position($lease, [], '2025-06-01');

        self::assertSame('2028-03-10', $position->endsOn->format('Y-m-d'));
        self::assertSame(36, $position->months);
        self::assertSame('30000.000', $position->allowanceKm);
    }

    public function testHeadingOverProjectsTheExcessAndItsCharge(): void
    {
        // 10,000 mi in the first year: on for 30,000 against 24,000, 6,000 over at 9p.
        $position = $this->position($this->pcp(), [
            ['2025-01-01', 1000],
            ['2026-01-01', 11000],
        ], '2026-01-10');

        self::assertEqualsWithDelta(10000.0, $this->miles((string) $position->distanceKm), 0.01);
        self::assertEqualsWithDelta(30000.0, $this->miles((string) $position->projectedKm), 1.0);
        self::assertTrue($position->isOver());
        self::assertEqualsWithDelta(25.0, (float) $position->excessPercent(), 0.01);
        self::assertSame(6000, $position->excessRounded());
        self::assertNotNull($position->excessCharge);
        self::assertSame('540.000000', $position->excessCharge->toDecimal(6));
    }

    public function testHeadingUnderShowsHowFarUnder(): void
    {
        // 5,000 mi a year: on for 15,000, 9,000 under; no charge.
        $position = $this->position($this->pcp(), [
            ['2025-01-01', 1000],
            ['2026-01-01', 6000],
        ], '2026-01-10');

        self::assertFalse($position->isOver());
        self::assertSame(-9000, $position->excessRounded());
        self::assertNull($position->excessCharge);
    }

    public function testNoProjectionWithoutAWeekOfReadings(): void
    {
        $position = $this->position($this->pcp(), [
            ['2025-01-01', 1000],
            ['2025-01-04', 1100],
        ], '2025-01-05');

        self::assertEqualsWithDelta(100.0, $this->miles((string) $position->distanceKm), 0.01);
        self::assertNull($position->projectedKm);
        self::assertNull($position->excessKm);
        self::assertFalse($position->isOver());
    }

    public function testTheStartOdometerIsTheReadingNearestTheAgreementDateUnlessEntered(): void
    {
        $readings = [['2024-11-01', 500], ['2024-12-28', 900], ['2025-03-01', 2000]];

        $looked = $this->position($this->pcp(), $readings, '2025-03-02');
        self::assertEqualsWithDelta(900.0, $this->miles((string) $looked->startKm), 0.01);
        self::assertEqualsWithDelta(1100.0, $this->miles((string) $looked->distanceKm), 0.01);

        $entered = $this->position($this->pcp(['startOdometerKm' => (string) (950 * self::MI)]), $readings, '2025-03-02');
        self::assertEqualsWithDelta(1050.0, $this->miles((string) $entered->distanceKm), 0.01);
    }

    public function testKilometresAndMilesGiveTheSameDistanceInTheirOwnUnit(): void
    {
        $km = $this->pcp([
            'annualMileageAllowance' => 12000,
            'mileageUnit' => DistanceUnit::Kilometre,
            'excessMileageCharge' => '0.06',
        ]);
        // 18,000 km in the first year: on for 54,000 km against 36,000, 18,000 over at 6c.
        $position = $this->position($km, [
            ['2025-01-01', 1000 / self::MI],
            ['2026-01-01', 19000 / self::MI],
        ], '2026-01-10');

        self::assertSame('36000.000', $position->allowanceKm);
        self::assertSame(18000, $position->excessRounded());
        self::assertSame('1080.000000', $position->excessCharge?->toDecimal(6));
    }

    public function testAnEndedAgreementUsesTheDistanceAtTheEnd(): void
    {
        $ended = $this->pcp([], AgreementStatus::HandedBack, '2028-01-01');
        $position = $this->position($ended, [
            ['2025-01-01', 1000],
            ['2028-01-01', 26000],
        ], '2028-02-01');

        self::assertEqualsWithDelta(25000.0, $this->miles((string) $position->projectedKm), 0.01);
        self::assertSame(1000, $position->excessRounded());
        self::assertSame('90.000000', $position->excessCharge?->toDecimal(6));
    }

    public function testNoAllowanceNoPosition(): void
    {
        $noAllowance = FinanceFixtures::agreement([
            'type' => AgreementType::Pcp,
            'startedOn' => '2025-01-01',
            'firstPaymentOn' => '2025-02-01',
            'numberOfPayments' => 35,
            'regularPayment' => '250',
            'finalPayment' => '9450',
        ]);

        self::assertNull($this->positionOrNull(FinanceFixtures::hp(), [], '2025-01-01'));
        self::assertNull($this->positionOrNull($noAllowance, [], '2025-01-01'));
    }

    /**
     * @param array{
     *     startOdometerKm?: string,
     *     annualMileageAllowance?: int,
     *     mileageUnit?: DistanceUnit,
     *     excessMileageCharge?: string,
     * } $overrides
     */
    private function pcp(
        array $overrides = [],
        AgreementStatus $status = AgreementStatus::Active,
        ?string $endedOn = null,
    ): FinanceAgreement {
        return FinanceFixtures::agreement($overrides + [
            'type' => AgreementType::Pcp,
            'startedOn' => '2025-01-01',
            'firstPaymentOn' => '2025-02-01',
            'numberOfPayments' => 35,
            'regularPayment' => '250',
            'finalPayment' => '9450',
            'cashPrice' => '25000',
            'apr' => '0',
            'annualMileageAllowance' => 8000,
            'excessMileageCharge' => '0.09',
        ], $status, $endedOn);
    }

    /**
     * @param list<array{0: string, 1: int|float}> $readings date, miles
     */
    private function position(FinanceAgreement $agreement, array $readings, string $today): MileagePosition
    {
        $position = $this->positionOrNull($agreement, $readings, $today);
        self::assertNotNull($position);

        return $position;
    }

    /**
     * @param list<array{0: string, 1: int|float}> $readings date, miles
     */
    private function positionOrNull(FinanceAgreement $agreement, array $readings, string $today): ?MileagePosition
    {
        $list = [];
        foreach ($readings as $i => [$on, $miles]) {
            $at = new DateTimeImmutable($on . ' 12:00:00', new DateTimeZone('UTC'));
            $km = number_format($miles * self::MI, 3, '.', '');
            $list[] = new OdometerReading($i + 1, 1, $km, $at, OdometerSource::Manual, null, null, $at, $at);
        }
        return MileageAllowance::of(
            $agreement,
            new OdometerHistory($list),
            FinanceFixtures::date($today),
            new DateTimeZone('Europe/London'),
            'GBP',
        );
    }

    private function miles(string $km): float
    {
        return (float) $km / self::MI;
    }
}
