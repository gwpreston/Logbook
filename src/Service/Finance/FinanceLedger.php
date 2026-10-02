<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;

/**
 * An agreement's derived cost lines (spec.md §7.32 *Costs*), never stored,
 * and the manual finance expenses they may overlap.
 *
 * Only payments already counted as paid make lines, so a period never holds
 * a payment still to come. HP, PCP and loans add each payment's interest
 * share and the fees, never capital (the purchase price is the capital);
 * once ended, one adjustment on the end date makes the lines total the
 * exact cost of credit. Leases add every rental and fee.
 */
final class FinanceLedger
{
    /**
     * @return list<FinanceLine> oldest first; none when `count_in_costs` is off
     */
    public static function lines(FinanceAgreement $agreement, FinanceFigures $figures): array
    {
        $data = $agreement->data;
        if (!$data->countInCosts) {
            return [];
        }
        $schedule = $figures->schedule;
        $endedOn = $agreement->status->isActive() ? null : self::endDate($agreement, $schedule);
        $on = static fn (DateTimeImmutable $date): DateTimeImmutable => $endedOn !== null && $date > $endedOn ? $endedOn : $date;
        $lines = [];

        $lease = $data->type === AgreementType::Lease;
        foreach ($schedule->paid() as $payment) {
            $amount = $lease ? $payment->amount : ($figures->interest[$payment->number] ?? '0');
            $kind = $lease ? FinanceLineKind::Rental : FinanceLineKind::Interest;
            $lines[] = new FinanceLine($on($payment->dueOn), $amount, $kind);
        }

        $first = $schedule->first();
        if ($data->documentationFee !== null && $first !== null && $first->status->isPaid()) {
            $lines[] = new FinanceLine($on($first->dueOn), $data->documentationFee, FinanceLineKind::Fee);
        }
        $final = $schedule->final();
        $finalPaid = $final !== null && $final->status->isPaid();
        if ($data->optionToPurchaseFee !== null && ($finalPaid || $agreement->status === AgreementStatus::Completed)) {
            $date = $on($final->dueOn ?? $endedOn ?? $data->startedOn);
            $lines[] = new FinanceLine($date, $data->optionToPurchaseFee, FinanceLineKind::Fee);
        }

        $exact = AgreementFigures::exactCost($agreement, $schedule);
        if ($exact !== null && $endedOn !== null) {
            $sum = BigDecimal::zero();
            foreach ($lines as $line) {
                $sum = $sum->plus($line->amount);
            }
            $difference = $exact->minus($sum);
            if (!$difference->isZero()) {
                $lines[] = new FinanceLine($endedOn, FinanceMath::money($difference), FinanceLineKind::Adjustment);
            }
        }

        $lines = array_values(array_filter(
            $lines,
            static fn (FinanceLine $line): bool => !BigDecimal::of($line->amount)->isZero(),
        ));
        usort($lines, static fn (FinanceLine $a, FinanceLine $b): int => $a->date <=> $b->date);

        return $lines;
    }

    /**
     * The months an agreement covers, as first and last calendar dates of
     * the month: from its first payment (a lease's initial rental) to its
     * final payment or end.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
     */
    public static function coverage(FinanceAgreement $agreement, PaymentSchedule $schedule): ?array
    {
        $first = $schedule->first();
        if ($first === null) {
            return null;
        }
        $last = $agreement->status->isActive() ? $schedule->endsOn() : self::endDate($agreement, $schedule);
        $last ??= $first->dueOn;

        return [$first->dueOn->modify('first day of this month'), $last->modify('last day of this month')];
    }

    /**
     * Manual *Finance and lease* expenses in the months an agreement covers,
     * which may count twice with it (spec.md §7.32 *Overlap warning*). None
     * when the agreement's lines are off.
     *
     * @param list<ExpenseEntry> $expenses the vehicle's
     * @return list<ExpenseEntry> oldest first
     */
    public static function overlapping(FinanceAgreement $agreement, PaymentSchedule $schedule, array $expenses): array
    {
        $coverage = self::coverage($agreement, $schedule);
        if (!$agreement->data->countInCosts || $coverage === null) {
            return [];
        }
        [$from, $until] = $coverage;
        $overlap = array_values(array_filter(
            $expenses,
            static fn (ExpenseEntry $entry): bool => $entry->data->category === ExpenseCategory::Finance
                && $entry->data->spentOn >= $from
                && $entry->data->spentOn <= $until,
        ));
        usort(
            $overlap,
            static fn (ExpenseEntry $a, ExpenseEntry $b): int => $a->data->spentOn <=> $b->data->spentOn ?: $a->id <=> $b->id,
        );

        return $overlap;
    }

    /** The day an ended agreement ended: its end date, else its settlement's, else its last payment. */
    private static function endDate(FinanceAgreement $agreement, PaymentSchedule $schedule): ?DateTimeImmutable
    {
        return $agreement->endedOn ?? $schedule->settlement?->paymentDate() ?? $schedule->endsOn();
    }
}
