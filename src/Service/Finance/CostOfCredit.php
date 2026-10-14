<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Support\Money\Money;

/**
 * What the credit costs (spec.md §7.32 *Cost of credit*): the agreement's
 * own figure, and what has been paid in interest and fees so far.
 */
final readonly class CostOfCredit
{
    public function __construct(
        /** Total amount payable − cash price (− amount of credit for a loan); null when a figure is missing. */
        public ?Money $total,
        /** Interest and fees so far: estimated while active, exact once ended. */
        public ?Money $soFar,
        public bool $exact,
        /** While active: the interest in the payments made, for the working (spec.md §8). */
        public ?Money $interestPaid = null,
        /** While active: the fees paid so far. */
        public ?Money $feesPaid = null,
        public int $paymentsMade = 0,
        /** Once ended: everything paid. */
        public ?Money $paidTotal = null,
        /** Once ended: what it is measured against (cash price, or the amount of credit, less a handed-back final payment). */
        public ?Money $base = null,
    ) {
    }
}
