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
    ) {
    }

    public function isEstimate(): bool
    {
        return $this->quote === null;
    }
}
