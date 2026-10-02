<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Service\Finance\AgreementFigures;
use Logbook\Service\Finance\FinanceFigures;
use Logbook\Service\Finance\FinanceMath;
use Logbook\Service\Finance\Schedule;
use Logbook\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * An agreement's figures (spec.md §7.32 *Figures*) against hand-worked
 * examples. The working (done independently, in Python's decimal module,
 * at the same roundings):
 *
 * - **HP at 9.9% APR** (FinanceFixtures::hp(): 12,000 of credit, 48 × 301.35).
 *   Monthly rate r = 1.099^(1/12) − 1 = 0.0078977469. Payment
 *   12,000 × r ÷ (1 − (1 + r)^−48) = 301.35. On 15 Jul 2026 (the 30th
 *   payment's date) 18 remain: remaining to pay 18 × 301.35 = 5,424.30;
 *   settlement Σ k=1..18 of 301.35 ÷ (1 + r)^k (each term to 10 places)
 *   = 5,037.89. Interest month by month (balance × r, to the penny):
 *   94.77, 93.14, 91.50, …; the first 30 sum to 2,078.28, all 48 to
 *   2,464.67. Total amount payable 3,000 + 48 × 301.35 = 17,464.80, so the
 *   agreement's cost of credit is 2,464.80. Half of it, 8,732.40, is
 *   reached by the 20th payment (3,000 + 20 × 301.35 = 9,027.00; 19 give
 *   8,725.65), due 15 Sep 2025.
 * - **PCP at 0%** (FinanceFixtures::pcp()): payments 36 × 250 + final
 *   8,000 = the 17,000 of credit; total 2,000 + 1,000 + 9,000 + 8,000 =
 *   20,000; cost of credit 0. After 18 payments: 4,500 remaining, the 8,000
 *   beside it, settlement 12,500 (no discounting at 0%).
 * - **Loan at 6.9% APR** (FinanceFixtures::loan()): r = 0.0055757898;
 *   8,000 over 36 gives 245.89; total 8,852.04; cost of credit against the
 *   amount of credit 852.04 (#122). The first 12 months' interest is 459.82.
 * - **Lease** (FinanceFixtures::lease()): 1,500 + 23 × 300 + 200 = 8,600
 *   payable; no settlement, cost of credit, half-paid point or equity.
 */
final class AgreementFiguresTest extends TestCase
{
    public function testMonthlyRateFromTheApr(): void
    {
        self::assertSame('0.0078977469', (string) FinanceMath::monthlyRate('9.9'));
        self::assertSame('0.0055757898', (string) FinanceMath::monthlyRate('6.9'));
        self::assertSame('0.0100000000', (string) FinanceMath::monthlyRate('12.682503'), '1.01^12 = 1.126825…');
        self::assertSame('0.0000000000', (string) FinanceMath::monthlyRate('0'));
    }

    public function testHpRemainingToPayIsExactAndTheSettlementMatchesTheWorkedPresentValue(): void
    {
        $figures = $this->figures(FinanceFixtures::hp(), '2026-07-15');

        self::assertSame(30, $figures->schedule->made());
        self::assertSame(18, $figures->schedule->remaining());
        self::assertSame('5424.30', $figures->remainingToPay->toDecimal(2));
        self::assertNull($figures->optionalFinal);
        self::assertNotNull($figures->settlement);
        self::assertTrue($figures->settlement->isEstimate());
        self::assertSame('5037.89', $figures->settlement->amount->toDecimal(2));
        self::assertSame('17464.80', $figures->totalAmountPayable->toDecimal(2));
        self::assertTrue($figures->totalDerived);
        self::assertSame('12000.00', $figures->amountOfCredit?->toDecimal(2));
    }

    public function testHpInterestSplitAndCostOfCredit(): void
    {
        $figures = $this->figures(FinanceFixtures::hp(), '2026-07-15');

        self::assertSame(['94.77', '93.14', '91.50'], [$figures->interest[1], $figures->interest[2], $figures->interest[3]]);
        $sum = \Brick\Math\BigDecimal::zero();
        foreach ($figures->interest as $share) {
            $sum = $sum->plus($share);
        }
        self::assertSame('2464.67', (string) $sum, 'all 48 payments');
        self::assertNotNull($figures->costOfCredit);
        self::assertSame('2464.80', $figures->costOfCredit->total?->toDecimal(2));
        self::assertSame('2078.28', $figures->costOfCredit->soFar?->toDecimal(2), 'estimated: 30 payments’ interest');
        self::assertFalse($figures->costOfCredit->exact);
    }

    public function testHpHalfPaidDate(): void
    {
        $figures = $this->figures(FinanceFixtures::hp(), '2026-07-15');

        self::assertNotNull($figures->halfPaid);
        self::assertSame('8732.40', $figures->halfPaid->target->toDecimal(2));
        self::assertTrue($figures->halfPaid->reached);
        self::assertSame('2025-09-15', $figures->halfPaid->on?->format('Y-m-d'));
        self::assertSame('0.00', $figures->halfPaid->stillNeeded->toDecimal(2));

        $early = $this->figures(FinanceFixtures::hp(), '2025-01-15');
        self::assertNotNull($early->halfPaid);
        self::assertFalse($early->halfPaid->reached);
        self::assertSame('2025-09-15', $early->halfPaid->on?->format('Y-m-d'), 'when the payments still due get there');
        // 3,000 + 12 × 301.35 = 6,616.20 paid; 8,732.40 − 6,616.20 still needed.
        self::assertSame('2116.20', $early->halfPaid->stillNeeded->toDecimal(2));
    }

    public function testEquityPositiveAndNegativeAndHiddenWithoutARecentValuation(): void
    {
        $positive = $this->figures(FinanceFixtures::hp(), '2026-07-15', valuation: ['2026-06-01', '7200']);
        self::assertNotNull($positive->equity);
        self::assertTrue($positive->equity->isPositive());
        self::assertSame('2162.11', $positive->equity->amount->toDecimal(2), '7,200 − 5,037.89');

        $negative = $this->figures(FinanceFixtures::hp(), '2026-07-15', valuation: ['2026-06-01', '4000']);
        self::assertNotNull($negative->equity);
        self::assertFalse($negative->equity->isPositive());
        self::assertSame('1037.89', $negative->equity->magnitude()->toDecimal(2));

        $stale = $this->figures(FinanceFixtures::hp(), '2026-07-15', valuation: ['2025-07-14', '7200']);
        self::assertNull($stale->equity);
        self::assertTrue($stale->equityNeedsValuation);

        $none = $this->figures(FinanceFixtures::hp(), '2026-07-15');
        self::assertNull($none->equity);
        self::assertTrue($none->equityNeedsValuation);
    }

    public function testALendersQuoteReplacesTheEstimateUntilItExpires(): void
    {
        $quote = new SettlementQuote(
            1,
            1,
            FinanceFixtures::date('2026-07-10'),
            '7612.08',
            FinanceFixtures::date('2026-07-31'),
            null,
        );

        $valid = $this->figures(FinanceFixtures::hp(), '2026-07-15', quotes: [$quote]);
        self::assertNotNull($valid->settlement);
        self::assertFalse($valid->settlement->isEstimate());
        self::assertSame('7612.08', $valid->settlement->amount->toDecimal(2));

        $expired = $this->figures(FinanceFixtures::hp(), '2026-08-01', quotes: [$quote]);
        self::assertNotNull($expired->settlement);
        self::assertTrue($expired->settlement->isEstimate());
    }

    public function testExtraPaymentsReduceTheSettlementEstimate(): void
    {
        $events = [FinanceFixtures::event(PaymentEventKind::Extra, null, '500', '2026-01-10')];
        $figures = $this->figures(FinanceFixtures::hp(), '2026-07-15', $events);

        self::assertSame('4537.89', $figures->settlement?->amount->toDecimal(2));
        self::assertSame('5424.30', $figures->remainingToPay->toDecimal(2), 'remaining to pay is the agreement’s schedule');
    }

    public function testExactCostOfCreditAfterSettlement(): void
    {
        $events = [FinanceFixtures::event(PaymentEventKind::Settlement, null, '5000', '2026-07-20')];
        $figures = $this->figures(FinanceFixtures::hp(AgreementStatus::Settled, '2026-07-20'), '2026-10-01', $events);

        self::assertNull($figures->settlement, 'nothing left to settle');
        self::assertNull($figures->equity);
        self::assertNotNull($figures->costOfCredit);
        self::assertTrue($figures->costOfCredit->exact);
        // 3,000 + 30 × 301.35 + 5,000 − 15,000.
        self::assertSame('2040.50', $figures->costOfCredit->soFar?->toDecimal(2));
        self::assertSame('0.00', $figures->remainingToPay->toDecimal(2));
    }

    public function testZeroPercentPcp(): void
    {
        // 18 payments made by 30 Jun 2026 (Jan 2025 to Jun 2026).
        $figures = $this->figures(FinanceFixtures::pcp(), '2026-06-30');

        self::assertSame(18, $figures->schedule->made());
        self::assertSame(18, $figures->schedule->remaining());
        self::assertSame('4500.00', $figures->remainingToPay->toDecimal(2));
        self::assertSame('8000', $figures->optionalFinal?->amount, 'the optional final payment, beside');
        self::assertSame('12500.00', $figures->settlement?->amount->toDecimal(2));
        self::assertSame('20000.00', $figures->totalAmountPayable->toDecimal(2));
        self::assertSame('17000.00', $figures->amountOfCredit?->toDecimal(2));
        self::assertNotNull($figures->costOfCredit);
        self::assertSame('0.00', $figures->costOfCredit->total?->toDecimal(2));
        self::assertSame('0.00', $figures->costOfCredit->soFar?->toDecimal(2));
        // Half of 20,000: deposits 3,000 + 28 × 250 = 10,000, the 28th payment on 30 Apr 2027.
        self::assertSame('2027-04-30', $figures->halfPaid?->on?->format('Y-m-d'));
    }

    public function testExactCostOfAHandedBackPcp(): void
    {
        $figures = $this->figures(FinanceFixtures::pcp(AgreementStatus::HandedBack, '2028-01-31'), '2028-02-10');

        // Paid 3,000 + 36 × 250 = 12,000; measured against 20,000 − 8,000 (#123).
        self::assertNotNull($figures->costOfCredit);
        self::assertTrue($figures->costOfCredit->exact);
        self::assertSame('0.00', $figures->costOfCredit->soFar?->toDecimal(2));
    }

    public function testLoanCostOfCreditIsAgainstTheAmountOfCredit(): void
    {
        $figures = $this->figures(FinanceFixtures::loan(), '2026-03-01');

        self::assertSame('8852.04', $figures->totalAmountPayable->toDecimal(2));
        self::assertNotNull($figures->costOfCredit);
        self::assertSame('852.04', $figures->costOfCredit->total?->toDecimal(2));
        self::assertSame('459.82', $figures->costOfCredit->soFar?->toDecimal(2), '12 payments’ interest');
        self::assertNull($figures->halfPaid, 'HP and PCP only');
        self::assertNotNull($figures->settlement);
    }

    public function testLeaseHasNoCreditFigures(): void
    {
        $figures = $this->figures(FinanceFixtures::lease(), '2025-06-10');

        self::assertSame('8600.00', $figures->totalAmountPayable->toDecimal(2));
        self::assertNull($figures->settlement);
        self::assertNull($figures->costOfCredit);
        self::assertNull($figures->halfPaid);
        self::assertNull($figures->equity);
        self::assertFalse($figures->equityNeedsValuation);
        self::assertSame(3, $figures->schedule->made(), 'April, May and June (due today); the initial rental is not one of them');
        self::assertSame('6000.00', $figures->remainingToPay->toDecimal(2), '20 × 300');
    }

    public function testEnteredTotalIsUsedOverTheDerivedOne(): void
    {
        $agreement = FinanceFixtures::agreement([
            'type' => \Logbook\Domain\Finance\AgreementType::Loan,
            'startedOn' => '2025-03-01',
            'firstPaymentOn' => '2025-04-01',
            'numberOfPayments' => 36,
            'regularPayment' => '245.89',
            'amountOfCredit' => '8000',
            'apr' => '6.9',
            'totalAmountPayable' => '8900',
        ]);
        $figures = $this->figures($agreement, '2025-04-01');

        self::assertFalse($figures->totalDerived);
        self::assertSame('900.00', $figures->costOfCredit?->total?->toDecimal(2));
    }

    /**
     * @param list<PaymentEvent> $events
     * @param list<SettlementQuote> $quotes
     * @param array{0: string, 1: string}|null $valuation valued on, amount
     */
    private function figures(
        FinanceAgreement $agreement,
        string $today,
        array $events = [],
        array $quotes = [],
        ?array $valuation = null,
    ): FinanceFigures {
        $day = FinanceFixtures::date($today);
        $value = $valuation === null ? null : new VehicleValuation(
            1,
            1,
            new VehicleValuationData(FinanceFixtures::date($valuation[0]), $valuation[1], null, null),
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );

        return AgreementFigures::of($agreement, Schedule::of($agreement, $events, $day), $quotes, $value, $day, 'GBP');
    }
}
