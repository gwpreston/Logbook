<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Derives an agreement's payment schedule (spec.md §7.32 *Schedule*). Never
 * stored: payment 1 on the first payment date (the first payment's own
 * amount when it differs), then the regular payments monthly on the same day
 * of the month, clamped to shorter months and always counted from the first
 * date, then the final payment. A lease's initial rental falls on the
 * agreement date.
 *
 * A payment due on or before $today (the vehicle owner's) is paid unless a
 * `missed` event says otherwise; a `paid_late` event then marks it made.
 * After a settlement, later payments leave the schedule. A handed-back PCP
 * never pays its final payment, and an agreement handed back or ended keeps
 * only the payments up to its end date. Once ended, every payment kept is
 * paid unless it was missed.
 */
final class Schedule
{
    /**
     * @param list<PaymentEvent> $events the agreement's events
     */
    public static function of(FinanceAgreement $agreement, array $events, DateTimeImmutable $today): PaymentSchedule
    {
        $data = $agreement->data;
        $planned = [];

        if ($data->type === AgreementType::Lease && $data->initialRental !== null) {
            $planned[] = [0, PaymentKind::InitialRental, $data->startedOn, $data->initialRental];
        }
        for ($i = 0; $i < $data->numberOfPayments; $i++) {
            $amount = $i === 0 && $data->firstPayment !== null ? $data->firstPayment : $data->regularPayment;
            $planned[] = [$i + 1, PaymentKind::Regular, LocalTime::addMonths($data->firstPaymentOn, $i), $amount];
        }
        if ($data->finalPayment !== null) {
            $planned[] = [
                $data->numberOfPayments + 1,
                PaymentKind::Final,
                self::finalPaymentOn($agreement),
                $data->finalPayment,
            ];
        }

        $missed = [];
        $late = [];
        $extras = [];
        $settlement = null;
        foreach ($events as $event) {
            $key = $event->dueOn?->format('Y-m-d');
            if ($event->kind === PaymentEventKind::Missed && $key !== null) {
                $missed[$key] = true;
            } elseif ($event->kind === PaymentEventKind::PaidLate && $key !== null) {
                $late[$key] = $event;
            } elseif ($event->kind === PaymentEventKind::Extra) {
                $extras[] = $event;
            } elseif ($event->kind === PaymentEventKind::Settlement) {
                $settlement = $event;
            }
        }

        $cutOff = self::cutOff($agreement, $settlement);
        $ended = !$agreement->status->isActive();
        $payments = [];
        foreach ($planned as [$number, $kind, $dueOn, $amount]) {
            if ($cutOff !== null && $dueOn > $cutOff) {
                continue;
            }
            if ($kind === PaymentKind::Final && $agreement->status === AgreementStatus::HandedBack) {
                continue;
            }
            $key = $dueOn->format('Y-m-d');
            $lateEvent = $late[$key] ?? null;
            $paidOn = null;
            if (isset($missed[$key]) && $lateEvent !== null) {
                $status = PaymentStatus::PaidLate;
                $paidOn = $lateEvent->paidOn;
            } elseif (!$ended && $dueOn > $today) {
                $status = PaymentStatus::Due;
            } else {
                $status = isset($missed[$key]) ? PaymentStatus::Missed : PaymentStatus::Paid;
            }
            $payments[] = new ScheduledPayment(
                number: $number,
                kind: $kind,
                dueOn: $dueOn,
                amount: Decimal::trim($amount),
                status: $status,
                paidOn: $paidOn,
            );
        }
        usort(
            $extras,
            static fn (PaymentEvent $a, PaymentEvent $b): int => $a->paymentDate() <=> $b->paymentDate() ?: $a->id <=> $b->id,
        );

        return new PaymentSchedule($payments, $extras, $settlement, $data->numberOfPayments);
    }

    /**
     * The final payment's date: as entered, else one month after the last
     * regular payment (counted from the first date, so 31 Jan stays a
     * month end).
     */
    public static function finalPaymentOn(FinanceAgreement $agreement): DateTimeImmutable
    {
        $data = $agreement->data;

        return $data->finalPaymentOn ?? LocalTime::addMonths($data->firstPaymentOn, $data->numberOfPayments);
    }

    /**
     * The last date a payment can fall on: a settlement's, else the end of
     * an agreement handed back or ended. A completed one keeps every
     * payment (all were made).
     */
    private static function cutOff(FinanceAgreement $agreement, ?PaymentEvent $settlement): ?DateTimeImmutable
    {
        if ($settlement !== null) {
            return $settlement->paymentDate() ?? $agreement->endedOn;
        }

        return match ($agreement->status) {
            AgreementStatus::Settled, AgreementStatus::HandedBack, AgreementStatus::Ended => $agreement->endedOn,
            default => null,
        };
    }
}
