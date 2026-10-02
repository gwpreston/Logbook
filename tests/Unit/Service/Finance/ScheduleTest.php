<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Finance;

use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Service\Finance\PaymentKind;
use Logbook\Service\Finance\PaymentStatus;
use Logbook\Service\Finance\Schedule;
use Logbook\Service\Finance\ScheduledPayment;
use PHPUnit\Framework\TestCase;

/**
 * An agreement's derived schedule (spec.md §7.32 *Schedule*).
 */
final class ScheduleTest extends TestCase
{
    public function testFortyEightPaymentsFromTheThirtyFirstClampToMonthEnds(): void
    {
        $agreement = FinanceFixtures::agreement([
            'type' => AgreementType::Hp,
            'startedOn' => '2024-12-31',
            'firstPaymentOn' => '2025-01-31',
            'numberOfPayments' => 48,
            'regularPayment' => '200',
        ]);
        $schedule = Schedule::of($agreement, [], FinanceFixtures::date('2025-01-01'));
        $dates = array_map(static fn (ScheduledPayment $p): string => $p->dueOn->format('Y-m-d'), $schedule->payments);

        self::assertCount(48, $dates);
        self::assertSame(['2025-01-31', '2025-02-28', '2025-03-31', '2025-04-30'], array_slice($dates, 0, 4));
        self::assertSame('2028-02-29', $dates[37], 'a leap year: 29 Feb, counted from the first date, not from 28 Feb');
        self::assertSame('2028-12-31', $dates[47]);
        self::assertSame(48, $schedule->remaining());
        self::assertSame(0, $schedule->made());
    }

    public function testADifferentFirstPayment(): void
    {
        $agreement = FinanceFixtures::agreement([
            'type' => AgreementType::Loan,
            'startedOn' => '2025-01-01',
            'firstPaymentOn' => '2025-02-01',
            'numberOfPayments' => 3,
            'regularPayment' => '100',
            'firstPayment' => '125.50',
            'amountOfCredit' => '300',
        ]);
        $schedule = Schedule::of($agreement, [], FinanceFixtures::date('2025-01-01'));

        $amounts = array_map(static fn (ScheduledPayment $p): string => $p->amount, $schedule->payments);
        self::assertSame(['125.5', '100', '100'], $amounts);
    }

    public function testAPcpFinalPaymentFallsOneMonthAfterTheLast(): void
    {
        $schedule = Schedule::of(FinanceFixtures::pcp(), [], FinanceFixtures::date('2025-01-01'));
        $final = $schedule->final();

        self::assertNotNull($final);
        self::assertSame(PaymentKind::Final, $final->kind);
        self::assertSame('2027-12-31', $schedule->payments[35]->dueOn->format('Y-m-d'));
        self::assertSame('2028-01-31', $final->dueOn->format('Y-m-d'));
        self::assertSame('8000', $final->amount);
        self::assertSame(37, $final->number);
        self::assertSame(36, $schedule->remaining(), 'the final payment is counted separately');
    }

    public function testALeaseInitialRentalFallsOnTheAgreementDate(): void
    {
        $schedule = Schedule::of(FinanceFixtures::lease(), [], FinanceFixtures::date('2025-03-10'));
        $first = $schedule->first();

        self::assertNotNull($first);
        self::assertSame(PaymentKind::InitialRental, $first->kind);
        self::assertSame('2025-03-10', $first->dueOn->format('Y-m-d'));
        self::assertSame(PaymentStatus::Paid, $first->status, 'due today counts as paid');
        self::assertCount(24, $schedule->payments);
        self::assertSame(23, $schedule->remaining(), 'the initial rental is not one of the 23');
    }

    public function testPaymentsDueOnOrBeforeTodayArePaid(): void
    {
        // The 30th payment of the HP falls on 15 Jul 2026.
        $schedule = Schedule::of(FinanceFixtures::hp(), [], FinanceFixtures::date('2026-07-15'));

        self::assertSame(30, $schedule->made());
        self::assertSame(18, $schedule->remaining());
        self::assertSame('2026-08-15', $schedule->next()?->dueOn->format('Y-m-d'));

        $before = Schedule::of(FinanceFixtures::hp(), [], FinanceFixtures::date('2026-07-14'));
        self::assertSame(29, $before->made());
    }

    public function testMissedAndPaidLateEvents(): void
    {
        $events = [
            FinanceFixtures::event(PaymentEventKind::Missed, '2024-04-15', id: 1),
            FinanceFixtures::event(PaymentEventKind::Missed, '2024-05-15', id: 2),
            FinanceFixtures::event(PaymentEventKind::PaidLate, '2024-05-15', paidOn: '2024-05-29', id: 3),
        ];
        $schedule = Schedule::of(FinanceFixtures::hp(), $events, FinanceFixtures::date('2024-06-01'));

        self::assertSame(PaymentStatus::Missed, $schedule->payments[2]->status);
        self::assertSame(PaymentStatus::PaidLate, $schedule->payments[3]->status);
        self::assertSame('2024-05-29', $schedule->payments[3]->paidOn?->format('Y-m-d'));
        self::assertCount(1, $schedule->missed());
        self::assertSame(3, $schedule->made(), 'Feb, Mar and the late May');
        self::assertSame(45, $schedule->remaining(), 'the missed April is still owed');
    }

    public function testAMissedEventForAFutureDateWaitsUntilItIsDue(): void
    {
        $events = [FinanceFixtures::event(PaymentEventKind::Missed, '2024-04-15')];
        $schedule = Schedule::of(FinanceFixtures::hp(), $events, FinanceFixtures::date('2024-04-01'));

        self::assertSame(PaymentStatus::Due, $schedule->payments[2]->status);
    }

    public function testExtraAndSettlementPayments(): void
    {
        $events = [
            FinanceFixtures::event(PaymentEventKind::Extra, null, '500', '2024-06-20', id: 1),
            FinanceFixtures::event(PaymentEventKind::Settlement, null, '5000', '2026-07-20', id: 2),
        ];
        $schedule = Schedule::of(
            FinanceFixtures::hp(AgreementStatus::Settled, '2026-07-20'),
            $events,
            FinanceFixtures::date('2026-10-01'),
        );

        self::assertCount(1, $schedule->extras);
        self::assertSame('500', $schedule->extras[0]->amount);
        self::assertSame('5000', $schedule->settlement?->amount);
        self::assertCount(30, $schedule->payments, 'later payments leave the schedule');
        self::assertSame(30, $schedule->made());
        self::assertSame(0, $schedule->remaining());
        self::assertSame('2026-07-15', $schedule->endsOn()?->format('Y-m-d'));
    }

    public function testAHandedBackPcpNeverPaysItsFinalPayment(): void
    {
        $schedule = Schedule::of(
            FinanceFixtures::pcp(AgreementStatus::HandedBack, '2028-01-31'),
            [],
            FinanceFixtures::date('2028-02-10'),
        );

        self::assertNull($schedule->final());
        self::assertSame(36, $schedule->made());
    }

    public function testACompletedAgreementKeepsEveryPaymentPaid(): void
    {
        $schedule = Schedule::of(
            FinanceFixtures::pcp(AgreementStatus::Completed, '2027-06-01'),
            [],
            FinanceFixtures::date('2027-06-02'),
        );

        self::assertSame(36, $schedule->made());
        self::assertSame(PaymentStatus::Paid, $schedule->final()?->status, 'the final payment was paid early');
    }
}
