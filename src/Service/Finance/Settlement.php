<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Support\Money\Money;

/**
 * What it would take to settle now (spec.md §7.32 *Settlement*): the
 * lender's quote while valid, else Logbook's estimate.
 */
final readonly class Settlement
{
    public function __construct(
        public Money $amount,
        /** The quote it comes from; null = an estimate. */
        public ?SettlementQuote $quote,
        /** An estimate's inputs, for its working (spec.md §8): the payments still owed. */
        public int $paymentCount = 0,
        /** Those payments added up, before discounting. */
        public ?Money $paymentsTotal = null,
        /** The agreement's monthly rate (10 places, a fraction: 0.0054 = 0.54%). */
        public ?string $monthlyRate = null,
        /** Extra payments already made, taken off the estimate. */
        public ?Money $extraPayments = null,
    ) {
    }

    public function isEstimate(): bool
    {
        return $this->quote === null;
    }
}
