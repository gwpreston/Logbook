<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Finance\EndAgreement;
use Logbook\Service\Finance\FinanceInput;
use Logbook\Service\Finance\FinanceLine;
use Logbook\Service\Finance\FinanceLineKind;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Finance\PaymentStatus;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *Finance settled early, a missed payment, a balloon
 * refinanced, and a 0% agreement*; spec §8 *Money edge cases*, bullet
 * **Finance**, with §7.32 *Schedule*, *Figures*, *Ending* and *Costs*
 * (decided #387, #390, #121; #392: missed payments, 0% APR and refinancing,
 * where spec and code already agreed).
 *
 * The HP agreement used throughout: cash price £1,000, no deposit, 12
 * payments of £90 on the 15th from 15 Feb 2026, at 8% APR. Its interest
 * shares, worked from the spec's rule (the balance from the amount of
 * credit, each share the balance times the monthly rate for the months
 * since the last payment, rounded to the penny): 6.43, 5.90, 5.36, 4.81,
 * 4.26, 3.71, 3.16, 2.60, ... Today is 5 Oct 2026, so eight payments are
 * paid.
 */
final class FinanceTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';

    public function testSettledEarlyDropsLaterPaymentsAndTheEndAdjustmentCanBeNegative(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-15', '1000');
        $id = $this->agreement($app, $owner, $car, '8');
        $finance = $this->service($app, FinanceService::class);
        $finance->end($owner, $car, $finance->get($owner, $car, $id), new EndAgreement(
            AgreementStatus::Settled,
            self::day('2026-10-01'),
            '300.00',
        ));

        $view = $finance->view($owner, $car, $finance->get($owner, $car, $id));
        $dates = array_map(static fn ($p): string => $p->dueOn->format('Y-m-d'), $view->figures->schedule->payments);
        self::assertCount(8, $dates, 'the 15 Oct payment and the rest leave the schedule');
        self::assertSame('2026-09-15', end($dates));
        self::assertSame('0.00', $view->figures->remainingToPay->toDecimal(2), 'nothing left to pay');

        // Paid: 8 x 90 + 300 = 1,020 against a cash price of 1,000: the credit cost 20.
        $lines = $this->lines($app, $owner, $car);
        self::assertCount(9, $lines, 'eight interest lines and the adjustment');
        $adjustment = $lines[8];
        self::assertSame(FinanceLineKind::Adjustment, $adjustment->kind);
        self::assertSame('2026-10-01', $adjustment->date->format('Y-m-d'), 'on the end date');
        self::assertSame('-16.23', $adjustment->amount, '20.00 less the 36.23 of interest counted so far: negative, kept');
        self::assertSame('20.00', self::total($lines), 'the lines add up to the exact cost of credit');
        self::assertSame([], array_filter($lines, static fn (FinanceLine $l): bool => $l->date->format('Y-m-d') > '2026-10-01'));
    }

    public function testAMissedPaymentIsOwedNowMakesNoCostLineAndLeavesTheInterestSharesAsScheduled(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-15', '1000');
        $id = $this->agreement($app, $owner, $car, '8');
        $finance = $this->service($app, FinanceService::class);
        $before = $finance->view($owner, $car, $finance->get($owner, $car, $id));
        self::assertCount(8, $this->lines($app, $owner, $car), 'eight payments are paid');

        $finance->markPayment(
            $owner,
            $car,
            $finance->get($owner, $car, $id),
            PaymentEventKind::Missed,
            self::day('2026-06-15'),
            null,
        );
        $after = $finance->view($owner, $car, $finance->get($owner, $car, $id));

        $expected = [
            1 => '6.43', 2 => '5.90', 3 => '5.36', 4 => '4.81', 5 => '4.26', 6 => '3.71',
            7 => '3.16', 8 => '2.60', 9 => '2.03', 10 => '1.47', 11 => '0.90', 12 => '0.33',
        ];
        self::assertSame($expected, $before->figures->interest);
        self::assertSame($expected, $after->figures->interest, 'the shares stay as scheduled');

        $june = null;
        foreach ($after->figures->schedule->payments as $payment) {
            if ($payment->dueOn->format('Y-m-d') === '2026-06-15') {
                $june = $payment;
            }
        }
        self::assertNotNull($june);
        self::assertSame(PaymentStatus::Missed, $june->status);

        $lines = $this->lines($app, $owner, $car);
        self::assertCount(7, $lines, 'no line for the missed payment');
        self::assertSame(
            ['6.43', '5.90', '5.36', '4.81', '3.71', '3.16', '2.60'],
            array_map(static fn (FinanceLine $l): string => $l->amount, $lines),
            'the others are unchanged',
        );
        foreach ($lines as $line) {
            self::assertNotSame('2026-06-15', $line->date->format('Y-m-d'));
        }

        // Owed now, not discounted: the four still to come plus the missed one.
        self::assertSame('360.00', $before->figures->remainingToPay->toDecimal(2));
        self::assertSame('450.00', $after->figures->remainingToPay->toDecimal(2), 'the missed £90 is owed');
        self::assertNotNull($before->figures->settlement);
        self::assertNotNull($after->figures->settlement);
        self::assertSame(
            '90.00',
            Decimal::subtract(
                $after->figures->settlement->amount->toDecimal(2),
                $before->figures->settlement->amount->toDecimal(2),
            ),
            'the settlement estimate adds the missed payment undiscounted',
        );
    }

    public function testAZeroPercentAgreementHasNoInterestLinesAndItsSettlementIsTheSumOwed(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-15', '1080');
        $id = $this->agreement($app, $owner, $car, '0', cashPrice: '1080');
        $finance = $this->service($app, FinanceService::class);
        $view = $finance->view($owner, $car, $finance->get($owner, $car, $id));

        foreach ($view->figures->interest as $number => $share) {
            self::assertDecimal('0', $share, "payment {$number} carries no interest");
        }
        self::assertSame([], $this->lines($app, $owner, $car), 'no interest lines');
        self::assertSame([], $this->service($app, CostLedger::class)->items($owner, [$car]), 'and so none in the ledger');
        self::assertSame('360.00', $view->figures->remainingToPay->toDecimal(2), 'four payments of £90 to come');
        self::assertNotNull($view->figures->settlement);
        self::assertSame('360.00', $view->figures->settlement->amount->toDecimal(2), 'settlement = the sum owed');
    }

    public function testABalloonRefinancedIsTheFinalPaymentPaidTheAgreementEndedAndANewLoanWithNoDoubleCount(): void
    {
        // The final payment (the balloon, £5,000) falls due on 15 Oct 2026; today is 20 Nov.
        [$app, $owner] = $this->world('2026-11-20T10:00:00Z');
        $car = $this->car($app, 'Golf', '2025-10-15', '10000');
        $finance = $this->service($app, FinanceService::class);
        $first = $finance->create($owner, $car, new FinanceInput(new AgreementData(
            type: AgreementType::Pcp,
            lender: 'First Lender',
            agreementNumber: null,
            startedOn: self::day('2025-10-15'),
            firstPaymentOn: self::day('2025-11-15'),
            numberOfPayments: 11,
            regularPayment: '300',
            finalPayment: '5000',
            finalPaymentOn: self::day('2026-10-15'),
            cashPrice: '10000',
            customerDeposit: '2000',
            apr: '6.9',
        )));
        $finance->end($owner, $car, $finance->get($owner, $car, $first), new EndAgreement(
            AgreementStatus::Completed,
            self::day('2026-10-15'),
        ));
        $finance->create($owner, $car, new FinanceInput(new AgreementData(
            type: AgreementType::Loan,
            lender: 'Second Lender',
            agreementNumber: null,
            startedOn: self::day('2026-10-15'),
            firstPaymentOn: self::day('2026-11-15'),
            numberOfPayments: 12,
            regularPayment: '440',
            amountOfCredit: '5000',
            apr: '9.9',
        )));

        $firstAgreement = $finance->get($owner, $car, $first);
        self::assertSame(AgreementStatus::Completed, $firstAgreement->status);
        $paid = $finance->view($owner, $car, $firstAgreement)->figures->schedule->final();
        self::assertNotNull($paid);
        self::assertSame('2026-10-15', $paid->dueOn->format('Y-m-d'));
        self::assertTrue($paid->status->isPaid(), 'the final payment is paid on its date');
        self::assertSame($finance->active($car)?->data->lender, 'Second Lender', 'the new loan is the active agreement');

        $byAgreement = [];
        foreach ($finance->costLines($owner, [$car])[$car->id] ?? [] as $row) {
            $byAgreement[$row['agreement']->data->lender][] = $row['line'];
        }
        $old = $byAgreement['First Lender'] ?? [];
        $new = $byAgreement['Second Lender'] ?? [];
        foreach ($old as $line) {
            self::assertLessThanOrEqual('2026-10-15', $line->date->format('Y-m-d'), 'nothing from the old one after it ended');
        }
        self::assertCount(1, $new, 'the loan has had one payment');
        self::assertSame('2026-11-15', $new[0]->date->format('Y-m-d'), 'its first line is its first payment');
        self::assertSame(FinanceLineKind::Interest, $new[0]->kind);
        self::assertSame('39.49', $new[0]->amount, '5,000 at 9.9% APR for the first month: interest only, not the capital');

        // Paid under the first: 2,000 + 11 x 300 + 5,000 = 10,300 against a cash price of 10,000.
        self::assertSame('300.00', self::total($old), 'the old lines add up to its exact cost of credit');
        $ledger = $this->service($app, CostLedger::class)->items($owner, [$car]);
        $sum = '0';
        foreach ($ledger as $item) {
            $sum = Decimal::add($sum, $item->amount->toDecimal(2));
        }
        self::assertSame('339.49', $sum, 'the ledger holds both lines and not the £5,000 balloon (the loan\'s capital)');

        $report = $this->report($app, $owner, $car, '2025-10-01', '2026-11-30');
        self::assertSame('339.49', $report->total->toDecimal(2), 'Reports agrees');
    }

    /**
     * 12 payments of £90 on the 15th from 15 Feb 2026, started 15 Jan.
     *
     * @param App<ContainerInterface> $app
     */
    private function agreement(App $app, User $owner, Vehicle $car, string $apr, string $cashPrice = '1000'): int
    {
        return $this->service($app, FinanceService::class)->create($owner, $car, new FinanceInput(new AgreementData(
            type: AgreementType::Hp,
            lender: 'Lender',
            agreementNumber: null,
            startedOn: self::day('2026-01-15'),
            firstPaymentOn: self::day('2026-02-15'),
            numberOfPayments: 12,
            regularPayment: '90',
            cashPrice: $cashPrice,
            apr: $apr,
        )));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<FinanceLine>
     */
    private function lines(App $app, User $owner, Vehicle $car): array
    {
        $lines = [];
        foreach ($this->service($app, FinanceService::class)->costLines($owner, [$car])[$car->id] ?? [] as $row) {
            $lines[] = $row['line'];
        }

        return $lines;
    }

    /**
     * @param list<FinanceLine> $lines
     */
    private static function total(array $lines): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = Decimal::add($sum, $line->amount);
        }

        return Decimal::round($sum, 2);
    }
}
