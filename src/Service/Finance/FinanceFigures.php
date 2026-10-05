<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * Everything worked out from one agreement (spec.md §7.32 *Figures*). Every
 * estimate says it is one: the settlement (unless quoted), the cost of
 * credit so far (while active).
 */
final readonly class FinanceFigures
{
    /**
     * @param array<int, string> $interest each scheduled payment's interest share, by payment number (credit agreements)
     */
    public function __construct(
        public PaymentSchedule $schedule,
        /** The scheduled payments still due, the optional final payment left out (shown beside). Exact. */
        public Money $remainingToPay,
        /**
         * Everything paid so far (AgreementFigures::paidTotal): deposits,
         * payments made, extras, the settlement and fees paid. Exact.
         */
        public Money $paidSoFar,
        /** A PCP's optional final payment, shown beside what remains; null otherwise. */
        public ?ScheduledPayment $optionalFinal,
        public Money $totalAmountPayable,
        /** True when the total amount payable was worked out rather than entered. */
        public bool $totalDerived,
        public ?Money $amountOfCredit,
        /** Not for leases, nor once ended. */
        public ?Settlement $settlement,
        /** Not for leases. */
        public ?CostOfCredit $costOfCredit,
        /** HP and PCP. */
        public ?HalfPaidPoint $halfPaid,
        /** HP, PCP and loans with a valuation from the last 12 months, while active. */
        public ?Equity $equity,
        /** True when equity would be shown but there is no recent valuation. */
        public bool $equityNeedsValuation,
        public array $interest,
        public ?DateTimeImmutable $endsOn,
    ) {
    }
}
