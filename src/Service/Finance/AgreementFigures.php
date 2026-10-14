<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Support\AsOf;
use Logbook\Support\AsOfKind;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;

/**
 * Works out an agreement's figures (spec.md §7.32 *Figures*) from its
 * schedule, quotes and the latest valuation, in exact decimals.
 *
 * - *Remaining to pay* is exact: the scheduled payments still due (a PCP's
 *   optional final payment beside it, not in it).
 * - *Settlement* is the lender's quote while valid, else the present value
 *   of what is still owed at the APR's monthly rate, less extra payments.
 * - *Interest* is split from each payment as the balance (from the amount
 *   of credit) × the rate over the months since the previous payment; the
 *   rest is capital.
 * - *Cost of credit* is exact once ended: everything paid − the cash price
 *   (− the amount of credit for a loan; − (cash price − final payment) for a
 *   PCP handed back, #122, #123).
 */
final class AgreementFigures
{
    /** Valuations older than this many months don't give equity. */
    public const int VALUATION_MONTHS = 12;

    /**
     * @param list<SettlementQuote> $quotes newest first
     */
    public static function of(
        FinanceAgreement $agreement,
        PaymentSchedule $schedule,
        array $quotes,
        ?VehicleValuation $valuation,
        DateTimeImmutable $today,
        string $currency,
    ): FinanceFigures {
        $data = $agreement->data;
        $type = $data->type;
        $money = static fn (BigDecimal $amount): Money => Money::of(FinanceMath::money($amount), $currency);
        $rate = FinanceMath::monthlyRate($data->apr);

        $optionalFinal = null;
        $remaining = BigDecimal::zero();
        foreach ($schedule->owed() as $payment) {
            if ($payment->kind === PaymentKind::Final && $type->finalPaymentIsOptional()) {
                $optionalFinal = $payment;
                continue;
            }
            $remaining = $remaining->plus($payment->amount);
        }

        $derivedTotal = self::derivedTotal($data);
        $total = $data->totalAmountPayable === null ? $derivedTotal : BigDecimal::of($data->totalAmountPayable);
        $credit = self::amountOfCredit($data);
        $steps = $type->isCredit() && $credit !== null ? self::interestSteps($agreement, $credit, $rate) : [];
        $interest = [];
        foreach ($steps as $step) {
            $interest[$step->number] = $step->interest;
        }

        $settlement = null;
        if ($type->isCredit() && $agreement->status->isActive()) {
            $settlement = self::settlement($schedule, $quotes, $rate, $today, $money);
        }

        $equity = null;
        $needsValuation = false;
        if ($settlement !== null) {
            $recent = $valuation !== null
                && $valuation->data->valuedOn <= $today
                && $valuation->data->valuedOn >= LocalTime::addMonths($today, -self::VALUATION_MONTHS);
            if ($recent) {
                $value = Money::of($valuation->data->amount, $currency);
                $equity = new Equity(
                    $value,
                    $valuation->data->valuedOn,
                    $value->subtract($settlement->amount),
                    AsOf::of(AsOfKind::Valuation, $valuation->data->valuedOn, $today),
                );
            } else {
                $needsValuation = true;
            }
        }

        return new FinanceFigures(
            schedule: $schedule,
            remainingToPay: $money($remaining),
            paidSoFar: $money(self::paidTotal($agreement, $schedule)),
            optionalFinal: $optionalFinal,
            totalAmountPayable: $money($total),
            totalDerived: $data->totalAmountPayable === null,
            amountOfCredit: $credit === null ? null : $money($credit),
            settlement: $settlement,
            costOfCredit: $type->isCredit() ? self::costOfCredit($agreement, $schedule, $total, $interest, $money) : null,
            halfPaid: $type->hasHalfPaidPoint() ? self::halfPaid($data, $schedule, $total, $money) : null,
            equity: $equity,
            equityNeedsValuation: $needsValuation,
            interest: $interest,
            endsOn: $schedule->endsOn(),
            interestSteps: $steps,
            monthlyRate: (string) $rate,
        );
    }

    /**
     * The total amount payable worked out from the figures: deposits (the
     * customer's and the dealer's contribution, as UK paperwork counts them)
     * + first payment + regular payments + final payment + fees, or for a
     * lease initial rental + rentals + fees.
     */
    public static function derivedTotal(AgreementData $data): BigDecimal
    {
        $n = $data->numberOfPayments;
        $payments = FinanceMath::of($data->firstPayment ?? $data->regularPayment)
            ->plus(BigDecimal::of($data->regularPayment)->multipliedBy(max(0, $n - 1)));
        $upFront = $data->type === AgreementType::Lease
            ? FinanceMath::of($data->initialRental)
            : self::deposits($data);

        return $upFront
            ->plus($n > 0 ? $payments : BigDecimal::zero())
            ->plus(FinanceMath::of($data->finalPayment))
            ->plus(self::fees($data));
    }

    /** The customer's deposit and the dealer's contribution. */
    public static function deposits(AgreementData $data): BigDecimal
    {
        return BigDecimal::of($data->customerDeposit)->plus($data->dealerContribution);
    }

    /** Documentation and option-to-purchase fees together. */
    public static function fees(AgreementData $data): BigDecimal
    {
        return FinanceMath::of($data->documentationFee)->plus(FinanceMath::of($data->optionToPurchaseFee));
    }

    /**
     * The amount borrowed: as entered, else cash price − deposits (HP and
     * PCP). Null for a lease, or when nothing gives it.
     */
    public static function amountOfCredit(AgreementData $data): ?BigDecimal
    {
        if (!$data->type->isCredit()) {
            return null;
        }
        if ($data->amountOfCredit !== null) {
            return BigDecimal::of($data->amountOfCredit);
        }
        if ($data->cashPrice === null) {
            return null;
        }

        return BigDecimal::of($data->cashPrice)->minus($data->customerDeposit)->minus($data->dealerContribution);
    }

    /**
     * Each payment's interest share, by payment number, across the whole
     * agreement as written (spec.md §7.32 *Cost of credit*): the balance
     * starts at the amount of credit on the agreement date; each payment
     * pays the interest accrued since the previous one (never more than
     * itself, never less than nothing) and the rest reduces the balance.
     *
     * @return array<int, string> pennies
     */
    public static function interest(FinanceAgreement $agreement, BigDecimal $credit, BigDecimal $rate): array
    {
        $shares = [];
        foreach (self::interestSteps($agreement, $credit, $rate) as $step) {
            $shares[$step->number] = $step->interest;
        }

        return $shares;
    }

    /**
     * The same split, with the balance before and after each payment (the
     * working of *Interest so far*, spec.md §8).
     *
     * @return list<InterestStep>
     */
    public static function interestSteps(FinanceAgreement $agreement, BigDecimal $credit, BigDecimal $rate): array
    {
        $data = $agreement->data;
        $planned = [];
        for ($i = 0; $i < $data->numberOfPayments; $i++) {
            $planned[$i + 1] = [
                LocalTime::addMonths($data->firstPaymentOn, $i),
                $i === 0 && $data->firstPayment !== null ? $data->firstPayment : $data->regularPayment,
            ];
        }
        if ($data->finalPayment !== null) {
            $planned[$data->numberOfPayments + 1] = [Schedule::finalPaymentOn($agreement), $data->finalPayment];
        }

        $balance = $credit;
        $previous = $data->startedOn;
        $steps = [];
        foreach ($planned as $number => [$dueOn, $amount]) {
            $payment = BigDecimal::of($amount);
            $months = FinanceMath::monthsBetween($previous, $dueOn);
            $accrued = $balance->isPositive()
                ? $balance->multipliedBy(FinanceMath::growth($rate, $months)->minus(1))->toScale(2, RoundingMode::HalfUp)
                : BigDecimal::zero()->toScale(2);
            $share = $accrued->isGreaterThan($payment) ? $payment->toScale(2, RoundingMode::HalfUp) : $accrued;
            $before = $balance;
            $balance = $balance->plus($share)->minus($payment);
            $steps[] = new InterestStep(
                $number,
                $dueOn,
                (string) $payment->toScale(2, RoundingMode::HalfUp),
                (string) $before->toScale(2, RoundingMode::HalfUp),
                (string) $share,
                (string) $balance->toScale(2, RoundingMode::HalfUp),
            );
            $previous = $dueOn;
        }

        return $steps;
    }

    /**
     * @param list<SettlementQuote> $quotes newest first
     * @param callable(BigDecimal): Money $money
     */
    private static function settlement(
        PaymentSchedule $schedule,
        array $quotes,
        BigDecimal $rate,
        DateTimeImmutable $today,
        callable $money,
    ): Settlement {
        foreach ($quotes as $quote) {
            if ($quote->isValidOn($today)) {
                return new Settlement($money(BigDecimal::of($quote->amount)), $quote);
            }
        }

        $value = BigDecimal::zero();
        $count = 0;
        $undiscounted = BigDecimal::zero();
        foreach ($schedule->owed() as $payment) {
            $count++;
            $undiscounted = $undiscounted->plus($payment->amount);
            $months = FinanceMath::monthsUntil($today, $payment->dueOn);
            $value = $value->plus(
                BigDecimal::of($payment->amount)->dividedBy(FinanceMath::growth($rate, $months), 10, RoundingMode::HalfUp),
            );
        }
        $extras = BigDecimal::zero();
        foreach ($schedule->extras as $extra) {
            $value = $value->minus(FinanceMath::of($extra->amount));
            $extras = $extras->plus(FinanceMath::of($extra->amount));
        }

        return new Settlement(
            $money($value->isNegative() ? BigDecimal::zero() : $value),
            null,
            $count,
            $money($undiscounted),
            (string) $rate,
            $money($extras),
        );
    }

    /**
     * @param array<int, string> $interest
     * @param callable(BigDecimal): Money $money
     */
    private static function costOfCredit(
        FinanceAgreement $agreement,
        PaymentSchedule $schedule,
        BigDecimal $total,
        array $interest,
        callable $money,
    ): CostOfCredit {
        $data = $agreement->data;
        $base = $data->type->hasCashPrice() ? $data->cashPrice : $data->amountOfCredit;
        $charge = $base === null ? null : $money($total->minus($base));

        $exact = self::exactCost($agreement, $schedule);
        if ($exact !== null) {
            $exactBase = self::exactBase($agreement);

            return new CostOfCredit(
                $charge,
                $money($exact),
                true,
                paidTotal: $money(self::paidTotal($agreement, $schedule)),
                base: $exactBase === null ? null : $money($exactBase),
            );
        }

        $interestPaid = BigDecimal::zero();
        foreach ($schedule->paid() as $payment) {
            $interestPaid = $interestPaid->plus($interest[$payment->number] ?? '0');
        }
        $fees = self::feesPaid($agreement, $schedule);
        $soFar = $interestPaid->plus($fees);

        return new CostOfCredit(
            $charge,
            $agreement->status->isActive() ? $money($soFar) : null,
            false,
            interestPaid: $money($interestPaid),
            feesPaid: $money($fees),
            paymentsMade: count($schedule->paid()),
        );
    }

    /**
     * The exact cost of credit once an agreement has ended (settled,
     * completed or handed back): everything paid − the cash price (− the
     * amount of credit for a loan; − (cash price − final payment) when
     * handed back). Null while active, for a lease, or without the figure
     * it is measured against.
     */
    public static function exactCost(FinanceAgreement $agreement, PaymentSchedule $schedule): ?BigDecimal
    {
        $data = $agreement->data;
        $ended = in_array(
            $agreement->status,
            [AgreementStatus::Settled, AgreementStatus::Completed, AgreementStatus::HandedBack],
            true,
        );
        if (!$ended || !$data->type->isCredit()) {
            return null;
        }
        $base = self::exactBase($agreement);

        return $base === null ? null : self::paidTotal($agreement, $schedule)->minus($base);
    }

    /**
     * What an ended agreement's payments are measured against: the cash
     * price (the amount of credit for a loan), less the final payment when
     * handed back. Null without the figure.
     */
    public static function exactBase(FinanceAgreement $agreement): ?BigDecimal
    {
        $data = $agreement->data;
        $base = $data->type->hasCashPrice() ? $data->cashPrice : $data->amountOfCredit;
        if ($base === null) {
            return null;
        }
        $base = BigDecimal::of($base);
        if ($agreement->status === AgreementStatus::HandedBack) {
            $base = $base->minus(FinanceMath::of($data->finalPayment));
        }

        return $base;
    }

    /**
     * Everything paid under the agreement: the deposits (the dealer's
     * contribution included, as the total amount payable counts it),
     * payments made, extra payments, the settlement and fees paid.
     */
    public static function paidTotal(FinanceAgreement $agreement, PaymentSchedule $schedule): BigDecimal
    {
        $data = $agreement->data;
        $paid = $data->type === AgreementType::Lease ? BigDecimal::zero() : self::deposits($data);
        foreach ($schedule->paid() as $payment) {
            $paid = $paid->plus($payment->amount);
        }
        foreach ($schedule->extras as $extra) {
            $paid = $paid->plus(FinanceMath::of($extra->amount));
        }
        if ($schedule->settlement !== null) {
            $paid = $paid->plus(FinanceMath::of($schedule->settlement->amount));
        }

        return $paid->plus(self::feesPaid($agreement, $schedule));
    }

    /**
     * Fees paid so far: the documentation fee with the first payment, the
     * option-to-purchase fee with the final one (or on completing).
     */
    public static function feesPaid(FinanceAgreement $agreement, PaymentSchedule $schedule): BigDecimal
    {
        $data = $agreement->data;
        $fees = BigDecimal::zero();
        $first = $schedule->first();
        if ($first !== null && $first->status->isPaid()) {
            $fees = $fees->plus(FinanceMath::of($data->documentationFee));
        }
        $final = $schedule->final();
        if (($final !== null && $final->status->isPaid()) || $agreement->status === AgreementStatus::Completed) {
            $fees = $fees->plus(FinanceMath::of($data->optionToPurchaseFee));
        }

        return $fees;
    }

    /**
     * @param callable(BigDecimal): Money $money
     */
    private static function halfPaid(
        AgreementData $data,
        PaymentSchedule $schedule,
        BigDecimal $total,
        callable $money,
    ): HalfPaidPoint {
        $target = $total->dividedBy(2, 2, RoundingMode::HalfUp);

        // What is paid, when: the deposit on the agreement date, then each payment (the
        // documentation fee with the first), extra payments and a settlement on their dates.
        // Each entry: date, amount, paid (true), still due (false) or missed (null).
        $timeline = [[$data->startedOn, self::deposits($data), true]];
        foreach ($schedule->payments as $i => $payment) {
            $amount = BigDecimal::of($payment->amount);
            if ($i === 0) {
                $amount = $amount->plus(FinanceMath::of($data->documentationFee));
            }
            $timeline[] = [
                $payment->dueOn,
                $amount,
                $payment->status->isPaid() ? true : ($payment->status === PaymentStatus::Missed ? null : false),
            ];
        }
        foreach ([...$schedule->extras, ...($schedule->settlement === null ? [] : [$schedule->settlement])] as $event) {
            $date = $event->paymentDate();
            if ($date !== null) {
                $timeline[] = [$date, FinanceMath::of($event->amount), true];
            }
        }
        usort($timeline, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $paidSoFar = BigDecimal::zero();
        foreach ($timeline as [, $amount, $paid]) {
            if ($paid === true) {
                $paidSoFar = $paidSoFar->plus($amount);
            }
        }
        $reached = $paidSoFar->isGreaterThanOrEqualTo($target);

        // Reached: the date the payments made got there. Not yet: the date the payments
        // still due would get there (a missed payment's date has gone).
        $running = BigDecimal::zero();
        $on = null;
        foreach ($timeline as [$date, $amount, $paid]) {
            if ($paid === null || ($reached && $paid === false)) {
                continue;
            }
            $running = $running->plus($amount);
            if ($running->isGreaterThanOrEqualTo($target)) {
                $on = $date;
                break;
            }
        }
        $needed = $target->minus($paidSoFar);

        return new HalfPaidPoint(
            target: $money($target),
            paidSoFar: $money($paidSoFar),
            stillNeeded: $money($needed->isNegative() ? BigDecimal::zero() : $needed),
            on: $on,
            reached: $reached,
        );
    }
}
