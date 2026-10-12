<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\EndAgreement;
use Logbook\Service\Finance\FinanceInput;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *A payout or negative finance adjustment larger than the
 * month's spend*; spec §8 *Money edge cases*, bullet **Negative amounts**,
 * with §7.7 *Cost ledger*, §7.29 *Costs* and §7.35 (decided #387, #388,
 * #400: there are no refunds).
 *
 * Only the finance *Adjustment* is a negative ledger line, so Reports go
 * negative through it. An insurance payout is not a ledger line: it is taken
 * off cost of ownership and true cost (Reports' totals do not move) and, in
 * the same way, can take them below zero. Both show with a minus sign, and
 * so does the per-distance figure worked out from them; only the incident
 * card shows its own net as 0.
 *
 * Today is 5 Oct 2026.
 */
final class NegativeTotalsTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';
    private const string TODAY = '2026-10-05';

    public function testAnAdjustmentLargerThanTheMonthsSpendGivesANegativeMonthPeriodAndPerDistanceTotalInReports(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->settledForLess($app, $owner);
        $fmt = $this->shown($app, $owner);

        // October: the adjustment of -216.23 and £20.00 of fuel.
        $october = $this->report($app, $owner, $car, '2026-10-01', self::TODAY);
        self::assertSame('-196.230', $october->total->toDecimal(3), 'the month: -216.23 + 20.00, kept below zero');
        self::assertSame('-£196.23', $fmt->money($october->shown->total, 'GBP'), 'with its minus sign');
        self::assertSame('-£196.23', $fmt->money($october->shown->month($october->months[0]), 'GBP'));
        self::assertDecimal('200', $october->distanceKm);
        self::assertDecimal('-0.98115', $october->costPerKm, 'and so is the cost per distance');
        self::assertSame('-£1.579/mi', $fmt->perDistance($october->costPerKm, 'GBP'));

        // Over the year to date: 36.23 of interest, the adjustment of -216.23 and the fuel.
        $period = $this->report($app, $owner, $car, '2026-01-01', self::TODAY);
        self::assertSame('-160.000', $period->total->toDecimal(3), 'the period total is negative too');
        self::assertSame('-£160.00', $fmt->money($period->shown->total, 'GBP'));
        self::assertSame('-£180.00', $fmt->money($period->shown->group(CostGroup::Other), 'GBP'), 'finance lines are Other');
        self::assertSame('£20.00', $fmt->money($period->shown->group(CostGroup::Fuel), 'GBP'));
        self::assertSame(
            [CostGroup::Fuel, CostGroup::Other],
            array_map(static fn ($g): CostGroup => $g->group, $period->spentGroups()),
            'the group below zero is listed after the others',
        );
        foreach ($period->groups as $group) {
            if ($group->group === CostGroup::Other) {
                self::assertNull($group->share, 'with no share');
            }
        }
        self::assertDecimal('700', $period->distanceKm);
        self::assertSame('-£0.368/mi', $fmt->perDistance($period->costPerKm, 'GBP'));
    }

    public function testAnAdjustmentLargerThanTheSpendTakesOwnershipAndTrueCostBelowZeroWithAMinusSign(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->settledForLess($app, $owner);
        $fmt = $this->shown($app, $owner);

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        $ownership = $result->ownership;
        self::assertSame('-160.000', $ownership->running->toDecimal(3));
        self::assertSame('-£160.00', $fmt->money($ownership->running, 'GBP'), 'cost of ownership, with its minus sign');
        self::assertDecimal('-0.228571', $ownership->runningPerKm);
        self::assertSame('-£0.368/mi', $fmt->perDistance($ownership->perKm, 'GBP'), 'and per distance');

        $since = $result->sinceBought;
        self::assertSame('-£160.00', $fmt->money($since->total(), 'GBP'), 'true cost: the total');
        self::assertSame('-£180.00', $fmt->money($since->amount(TruePart::Other), 'GBP'), 'finance lines are Other');
        self::assertSame('-£0.368/mi', $fmt->perDistance($since->perKm, 'GBP'));
        self::assertDecimal('-0.228571', $since->perKm);
        self::assertSame(1, Decimal::compare('0', (string) $since->rate(TruePart::Other)), 'a negative part per distance');
    }

    public function testAPayoutLargerThanThePeriodsCostsTakesOwnershipAndTrueCostBelowZeroButNotReports(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        [$car] = $this->crashed($app, $owner);
        $fmt = $this->shown($app, $owner);

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        $ownership = $result->ownership;
        self::assertSame('-£870.01', $fmt->money($ownership->running, 'GBP'), '£309.99 of costs less a £1,180 payout');
        self::assertSame('-£770.01', $fmt->money($ownership->total, 'GBP'), 'with the £100 of depreciation');
        self::assertSame('£1,180.00', $fmt->money($ownership->payouts, 'GBP'));
        self::assertDecimal('-0.217503', $ownership->runningPerKm);
        self::assertSame('-£0.35/mi', $fmt->perDistance($ownership->runningPerKm, 'GBP'));
        self::assertDecimal('-0.192503', $ownership->perKm, 'running -0.217503 + depreciation 0.025');
        self::assertSame('-£0.31/mi', $fmt->perDistance($ownership->perKm, 'GBP'));

        $since = $result->sinceBought;
        self::assertSame('-£770.01', $fmt->money($since->total(), 'GBP'));
        self::assertSame('-£0.31/mi', $fmt->perDistance($since->perKm, 'GBP'));
        self::assertNotNull($since->payoutsPerKm);
        self::assertSame(1, Decimal::compare('0', $since->payoutsPerKm), 'the payouts are a negative part');
        self::assertSame('-£770.01', $fmt->money($result->years[0]->total(), 'GBP'), 'and the year');

        // A payout is not a ledger line: Reports count the costs as spent (§7.7).
        $report = $this->report($app, $owner, $car, '2026-01-01', self::TODAY);
        self::assertSame('£309.99', $fmt->money($report->shown->total, 'GBP'));
        self::assertDecimal('0.077498', $report->costPerKm, '309.99 over 4,000 km, to 6 places');
    }

    public function testThePagesShowTheNegativeTotalAndPerDistanceWithAMinusSign(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        [$car] = $this->crashed($app, $owner);
        $browser = $this->browserFor($app, 'owner');

        $vehicle = $this->pageText($browser, '/vehicles/' . $car->id);
        self::assertStringContainsString('-£770.01', $vehicle, 'the card: cost of ownership so far');
        self::assertStringContainsString('-£0.31/mi', $vehicle, 'and its rate');

        $ownership = $this->pageText($browser, '/reports/ownership');
        self::assertStringContainsString('-£770.01', $ownership);
        self::assertStringContainsString('-£0.31/mi', $ownership);

        $trueCost = $this->pageText($browser, '/reports/true-cost?vehicle=' . $car->id);
        self::assertStringContainsString('Since bought: -£0.31/mi', $trueCost);
    }

    public function testOnlyTheIncidentCardShowsItsOwnNetAsZero(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        [$car, $incident] = $this->crashed($app, $owner);

        $costs = $this->service($app, IncidentService::class)->costs($owner, $car, $incident);
        self::assertSame('249.990', $costs->linked->toDecimal(3));
        self::assertSame('1180.000', $costs->payouts->toDecimal(3));
        self::assertSame('0.000', $costs->net->toDecimal(3), 'the card shows its net as 0, not -930.01');
        self::assertTrue($costs->receivedMore, '"You received more than it cost"');

        $ownership = $this->trueCost($app, $owner, $car, self::TODAY)->ownership;
        self::assertTrue($ownership->running->isNegative(), 'cost of ownership keeps the sign');
    }

    /**
     * Bought for £1,000 and paid off early: 8 payments of £90 on the 15th
     * from 15 Feb (interest shares 6.43, 5.90, 5.36, 4.81, 4.26, 3.71, 3.16
     * and 2.60, 36.23 in all), settled on 1 Oct for £100. Everything paid is
     * 8 x 90 + 100 = 820, so the credit cost 820 - 1,000 = -180 and the
     * adjustment on 1 Oct is -180 - 36.23 = -216.23. Then £20.00 of fuel on
     * 2 Oct. Readings: 1,000 km on 15 Jan, 1,500 on 30 Sep, 1,600 with the
     * fill-up and 1,700 on 4 Oct.
     *
     * @param App<ContainerInterface> $app
     */
    private function settledForLess(App $app, User $owner): Vehicle
    {
        $car = $this->car($app, 'Golf', '2026-01-15', '1000');
        $this->reading($app, $car, '1000', '2026-01-15T12:00:00Z');
        $this->reading($app, $car, '1500', '2026-09-30T12:00:00Z');
        $finance = $this->service($app, FinanceService::class);
        $id = $finance->create($owner, $car, new FinanceInput(new AgreementData(
            type: AgreementType::Hp,
            lender: 'Lender',
            agreementNumber: null,
            startedOn: self::day('2026-01-15'),
            firstPaymentOn: self::day('2026-02-15'),
            numberOfPayments: 12,
            regularPayment: '90',
            cashPrice: '1000',
            apr: '8',
        )));
        $finance->end($owner, $car, $finance->get($owner, $car, $id), new EndAgreement(
            AgreementStatus::Settled,
            self::day('2026-10-01'),
            '100.00',
        ));
        $this->fillUp($app, $car, '2026-10-02T09:00:00Z', '1600', '14.000', '20.00');
        $this->reading($app, $car, '1700', '2026-10-04T12:00:00Z');

        return $car;
    }

    /**
     * Bought on 1 Jan for £10,000, worth £9,900 on 1 Sep: 4,000 km in all
     * (1,000 to 5,000). £60.00 of expenses and a £249.99 repair on 1 Mar,
     * which an incident on 1 May links; its claim paid out £1,180.
     *
     * @param App<ContainerInterface> $app
     * @return array{0: Vehicle, 1: Incident}
     */
    private function crashed(App $app, User $owner): array
    {
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '5000', '2026-09-01T12:00:00Z');
        $this->service($app, ValuationService::class)
            ->create($car, new VehicleValuationData(self::day('2026-09-01'), '9900'));
        $this->expense($app, $car, '2026-02-01', '60.00');
        $repair = $this->maintenance($app, $car, '2026-03-01', 'Bumper', '249.99');
        $incidents = $this->service($app, IncidentService::class);
        $incident = $incidents->create($car, new IncidentData(
            self::day('2026-05-01'),
            IncidentType::Collision,
            status: IncidentStatus::Closed,
            closedOn: self::day('2026-05-01'),
            claim: new Claim(ClaimStatus::Settled, 'Insurer', payout: '1180'),
        ), null, $owner->preferences->timeZone());
        $incidents->link($car, LinkKind::Maintenance, $repair->id, $incident);

        return [$car, $incident];
    }
}
