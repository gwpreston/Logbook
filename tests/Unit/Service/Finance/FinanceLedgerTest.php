<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Finance;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Service\Finance\AgreementFigures;
use Logbook\Service\Finance\FinanceLedger;
use Logbook\Service\Finance\FinanceLine;
use Logbook\Service\Finance\FinanceLineKind;
use Logbook\Service\Finance\Schedule;
use PHPUnit\Framework\TestCase;

/**
 * An agreement's derived cost lines and the overlap check (spec.md §7.32
 * *Costs*), with the HP, PCP and lease of FinanceFixtures (the working is
 * in AgreementFiguresTest).
 */
final class FinanceLedgerTest extends TestCase
{
    public function testHpAddsOnlyTheInterestOfPaymentsMadeNeverCapital(): void
    {
        $lines = $this->lines(FinanceFixtures::hp(), '2026-07-15');

        self::assertCount(30, $lines);
        self::assertSame([FinanceLineKind::Interest], array_values(array_unique(array_map(
            static fn (FinanceLine $line): FinanceLineKind => $line->kind,
            $lines,
        ), SORT_REGULAR)));
        self::assertSame('94.77', $lines[0]->amount);
        self::assertSame('2024-02-15', $lines[0]->date->format('Y-m-d'));
        self::assertSame('2078.28', $this->sum($lines), 'interest only: 30 × 301.35 of payments is not a cost');
    }

    public function testNeverAPaymentStillToCome(): void
    {
        $lines = $this->lines(FinanceFixtures::hp(), '2026-07-14');

        self::assertCount(29, $lines);
        self::assertLessThanOrEqual('2026-07-14', $lines[28]->date->format('Y-m-d'));
    }

    public function testMissedPaymentsAddNothingUntilPaid(): void
    {
        $events = [FinanceFixtures::event(PaymentEventKind::Missed, '2024-04-15')];

        self::assertCount(3, $this->lines(FinanceFixtures::hp(), '2024-05-15', $events), 'Feb, Mar, May');
    }

    public function testAfterSettlementTheLinesTotalTheExactCostOfCredit(): void
    {
        $events = [FinanceFixtures::event(PaymentEventKind::Settlement, null, '5000', '2026-07-20')];
        $lines = $this->lines(FinanceFixtures::hp(AgreementStatus::Settled, '2026-07-20'), '2026-10-01', $events);

        self::assertSame('2040.5', $this->sum($lines), '3,000 + 30 × 301.35 + 5,000 − 15,000');
        $last = end($lines);
        self::assertNotFalse($last);
        self::assertSame(FinanceLineKind::Adjustment, $last->kind);
        self::assertSame('2026-07-20', $last->date->format('Y-m-d'));
        self::assertSame('-37.78', $last->amount, '2,040.50 − 2,078.28 estimated');
    }

    public function testAZeroPercentPcpAddsNothing(): void
    {
        self::assertSame([], $this->lines(FinanceFixtures::pcp(), '2026-06-30'));
    }

    public function testFeesOnTheirDates(): void
    {
        $agreement = FinanceFixtures::agreement([
            'type' => AgreementType::Pcp,
            'startedOn' => '2024-12-31',
            'firstPaymentOn' => '2025-01-31',
            'numberOfPayments' => 2,
            'regularPayment' => '250',
            'finalPayment' => '8000',
            'cashPrice' => '8500',
            'apr' => '0',
            'documentationFee' => '199',
            'optionToPurchaseFee' => '10',
        ]);

        $active = $this->lines($agreement, '2025-02-28');
        self::assertCount(1, $active, 'the documentation fee with the first payment');
        self::assertSame('199', $active[0]->amount);
        self::assertSame('2025-01-31', $active[0]->date->format('Y-m-d'));

        $all = $this->lines($agreement, '2025-03-31');
        self::assertSame('209', $this->sum($all), 'the option-to-purchase fee with the final payment');
    }

    public function testALeaseAddsEveryRentalAndFeePaid(): void
    {
        $lines = $this->lines(FinanceFixtures::lease(), '2025-06-10');

        // 1,500 initial + 200 fee on 10 Mar, then April, May and June at 300.
        self::assertCount(5, $lines);
        self::assertSame('2600', $this->sum($lines));
        self::assertSame('2025-03-10', $lines[0]->date->format('Y-m-d'));
    }

    public function testCountInCostsOffAddsNothing(): void
    {
        $agreement = FinanceFixtures::agreement([
            'type' => AgreementType::Lease,
            'startedOn' => '2025-03-10',
            'firstPaymentOn' => '2025-04-10',
            'numberOfPayments' => 23,
            'regularPayment' => '300',
            'initialRental' => '1500',
            'countInCosts' => false,
        ]);

        self::assertSame([], $this->lines($agreement, '2025-06-10'));
    }

    public function testTheOverlapWarningListsManualFinanceExpensesInCoveredMonthsOnly(): void
    {
        $agreement = FinanceFixtures::lease();
        $schedule = Schedule::of($agreement, [], FinanceFixtures::date('2025-06-10'));
        $expenses = [
            $this->expense(1, '2025-02-28', ExpenseCategory::Finance),
            $this->expense(2, '2025-03-01', ExpenseCategory::Finance),
            $this->expense(3, '2025-04-10', ExpenseCategory::Parking),
            $this->expense(4, '2027-02-28', ExpenseCategory::Finance),
            $this->expense(5, '2027-03-01', ExpenseCategory::Finance),
        ];

        // The lease runs from March 2025 (the initial rental) to its 23rd rental in February 2027.
        $overlap = FinanceLedger::overlapping($agreement, $schedule, $expenses);
        self::assertSame([2, 4], array_map(static fn (ExpenseEntry $e): int => $e->id, $overlap));

        $off = FinanceFixtures::agreement(['countInCosts' => false] + $this->leaseData());
        $schedule = Schedule::of($off, [], FinanceFixtures::date('2025-06-10'));
        self::assertSame([], FinanceLedger::overlapping($off, $schedule, $expenses));
    }

    /**
     * @param list<PaymentEvent> $events
     * @return list<FinanceLine>
     */
    private function lines(FinanceAgreement $agreement, string $today, array $events = []): array
    {
        $day = FinanceFixtures::date($today);
        $schedule = Schedule::of($agreement, $events, $day);

        return FinanceLedger::lines($agreement, AgreementFigures::of($agreement, $schedule, [], null, $day, 'GBP'));
    }

    /**
     * @param list<FinanceLine> $lines
     */
    private function sum(array $lines): string
    {
        $sum = BigDecimal::zero();
        foreach ($lines as $line) {
            $sum = $sum->plus($line->amount);
        }

        return \Logbook\Support\Number\Decimal::trim((string) $sum);
    }

    private function expense(int $id, string $on, ExpenseCategory $category): ExpenseEntry
    {
        $now = new DateTimeImmutable();

        return new ExpenseEntry($id, 1, new ExpenseEntryData(FinanceFixtures::date($on), $category, '300'), $now, $now);
    }

    /**
     * @return array{
     *     type: AgreementType,
     *     startedOn: string,
     *     firstPaymentOn: string,
     *     numberOfPayments: int,
     *     regularPayment: string,
     *     initialRental: string,
     * }
     */
    private function leaseData(): array
    {
        return [
            'type' => AgreementType::Lease,
            'startedOn' => '2025-03-10',
            'firstPaymentOn' => '2025-04-10',
            'numberOfPayments' => 23,
            'regularPayment' => '300',
            'initialRental' => '1500',
        ];
    }
}
