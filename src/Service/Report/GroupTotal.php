<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;

/**
 * Spend in one cost group, with its share of the total.
 */
final readonly class GroupTotal
{
    public function __construct(
        public CostGroup $group,
        public Money $amount,
        /** Percentage of the total, 0–100 (for display and bar widths only). */
        public float $share,
    ) {
    }
}
