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
    ) {
    }
}
