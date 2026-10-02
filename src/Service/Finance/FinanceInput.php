<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Finance\AgreementData;

/**
 * A parsed agreement form: the agreement, and what to do with the vehicle's
 * purchase price (spec.md §7.32 *Purchase price*).
 */
final readonly class FinanceInput
{
    public function __construct(
        public AgreementData $data,
        /** HP or PCP, no purchase price yet: set it to the cash price. */
        public bool $setPurchasePrice = false,
        /** A lease, with a purchase price set: clear it. */
        public bool $clearPurchasePrice = false,
    ) {
    }
}
